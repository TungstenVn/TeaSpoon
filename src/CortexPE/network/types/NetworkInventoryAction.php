<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types = 1);

namespace CortexPE\network\types;

use CortexPE\inventory\AnvilInventory;
use CortexPE\inventory\EnchantInventory;
use CortexPE\Main;
use CortexPE\network\InventoryTransactionPacket;
use InvalidArgumentException;
use pocketmine\inventory\CraftingGrid;
use pocketmine\inventory\transaction\action\CreativeInventoryAction;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\InventoryAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\types\ContainerIds;
use pocketmine\Player;
use UnexpectedValueException;
use pocketmine\network\mcpe\protocol\types\NetworkInventoryAction as PMNetworkInventoryAction;
use pocketmine\network\mcpe\NetworkBinaryStream;
class NetworkInventoryAction extends PMNetworkInventoryAction{
	public const SOURCE_CONTAINER = 0;

	public const SOURCE_WORLD = 2; //drop/pickup item entity
	public const SOURCE_CREATIVE = 3;
	public const SOURCE_CRAFTING_GRID = 100;
	public const SOURCE_TODO = 99999;

	/**
	 * Fake window IDs for the SOURCE_TODO type (99999)
	 *
	 * These identifiers are used for inventory source types which are not currently implemented server-side in MCPE.
	 * As a general rule of thumb, anything that doesn't have a permanent inventory is client-side. These types are
	 * to allow servers to track what is going on in client-side windows.
	 *
	 * Expect these to change in the future.
	 */
	public const SOURCE_TYPE_CRAFTING_ADD_INGREDIENT = -2;
	public const SOURCE_TYPE_CRAFTING_REMOVE_INGREDIENT = -3;
	public const SOURCE_TYPE_CRAFTING_RESULT = -4;
	public const SOURCE_TYPE_CRAFTING_USE_INGREDIENT = -5;

	public const SOURCE_TYPE_ANVIL_INPUT = -10;
	public const SOURCE_TYPE_ANVIL_MATERIAL = -11;
	public const SOURCE_TYPE_ANVIL_RESULT = -12;
	public const SOURCE_TYPE_ANVIL_OUTPUT = -13;

	public const SOURCE_TYPE_ENCHANT_INPUT = -15;
	public const SOURCE_TYPE_ENCHANT_MATERIAL = -16;
	public const SOURCE_TYPE_ENCHANT_OUTPUT = -17;

	public const SOURCE_TYPE_TRADING_INPUT_1 = -20;
	public const SOURCE_TYPE_TRADING_INPUT_2 = -21;
	public const SOURCE_TYPE_TRADING_USE_INPUTS = -22;
	public const SOURCE_TYPE_TRADING_OUTPUT = -23;

	public const SOURCE_TYPE_BEACON = -24;

	/** Any client-side window dropping its contents when the player closes it */
	public const SOURCE_TYPE_CONTAINER_DROP_CONTENTS = -100;

	public const ACTION_MAGIC_SLOT_CREATIVE_DELETE_ITEM = 0;
	public const ACTION_MAGIC_SLOT_CREATIVE_CREATE_ITEM = 1;

	public const ACTION_MAGIC_SLOT_DROP_ITEM = 0;
	public const ACTION_MAGIC_SLOT_PICKUP_ITEM = 1;

	/** @var int */
	public $sourceType;
	/** @var int */
	public $windowId;
	/** @var int */
	public $sourceFlags = 0;
	/** @var int */
	public $inventorySlot;
	/** @var Item */
	public $oldItem;
	/** @var Item */
	public $newItem;

	/**
	 * @param InventoryTransactionPacket $packet
	 *
	 * @return $this
	 */
	public function read(NetworkBinaryStream $packet, bool $hasItemStackIds){
		$this->sourceType = $packet->getUnsignedVarInt();

		switch($this->sourceType){
			case self::SOURCE_WORLD:
				$this->sourceFlags = $packet->getUnsignedVarInt();
				break;
			case self::SOURCE_CREATIVE:
				break;
			case self::SOURCE_CONTAINER:
			case self::SOURCE_CRAFTING_GRID:
			case self::SOURCE_TODO:
				$this->windowId = $packet->getVarInt();
				break;
			default:
				throw new UnexpectedValueException("Unknown inventory action source type $this->sourceType");
		}

		$this->inventorySlot = $packet->getUnsignedVarInt();
		$this->oldItem = $packet->getSlot();
		$this->newItem = $packet->getSlot();

		return $this;
	}

	/**
	 * @param InventoryTransactionPacket $packet
	 */
	public function write(NetworkBinaryStream $packet, bool $hasItemStackIds){
		$packet->putUnsignedVarInt($this->sourceType);

		switch($this->sourceType){
			case self::SOURCE_WORLD:
				$packet->putUnsignedVarInt($this->sourceFlags);
				break;
			case self::SOURCE_CREATIVE:
				break;
			case self::SOURCE_CONTAINER:
			case self::SOURCE_CRAFTING_GRID:
			case self::SOURCE_TODO:
				$packet->putVarInt($this->windowId);
				break;
			default:
				throw new InvalidArgumentException("Unknown inventory action source type $this->sourceType");
		}

		$packet->putUnsignedVarInt($this->inventorySlot);
		$packet->putSlot($this->oldItem);
		$packet->putSlot($this->newItem);
	}

	/**
	 * @param Player $player
	 *
	 * @return InventoryAction|null
	 *
	 * @throws UnexpectedValueException
	 */
	public function createInventoryAction(Player $player){
		if($this->oldItem->equalsExact($this->newItem)){
			//filter out useless noise in 1.13
			return null;
		}

		switch($this->sourceType){
			case self::SOURCE_CONTAINER:
				if($this->windowId === ContainerIds::UI and $this->inventorySlot > 0){
					Main::getInstance()->getLogger()->debug("Container UI is being called.");

					if($this->inventorySlot === 50){
						Main::getInstance()->getLogger()->debug("Inventory Slot is maxed out");

						return null; //useless noise
					}
					if($this->inventorySlot >= 28 and $this->inventorySlot <= 31){
						$window = $player->getCraftingGrid();
						if($window->getGridWidth() !== CraftingGrid::SIZE_SMALL){
							throw new \UnexpectedValueException("Expected small crafting grid");
						}
						$slot = $this->inventorySlot - 28;
					}elseif($this->inventorySlot >= 32 and $this->inventorySlot <= 40){
						$window = $player->getCraftingGrid();
						if($window->getGridWidth() !== CraftingGrid::SIZE_BIG){
							throw new \UnexpectedValueException("Expected big crafting grid");
						}
						$slot = $this->inventorySlot - 32;
					}else{
						throw new \UnexpectedValueException("Unhandled magic UI slot offset $this->inventorySlot");
					}
				}else{
					$window = $player->getWindow($this->windowId);
					$slot = $this->inventorySlot;
				}
				if($window !== null){
					return new SlotChangeAction($window, $slot, $this->oldItem, $this->newItem);
				}

				throw new UnexpectedValueException("Player " . $player->getName() . " has no open container with window ID $this->windowId");
			case self::SOURCE_WORLD:
				if($this->inventorySlot !== self::ACTION_MAGIC_SLOT_DROP_ITEM){
					throw new UnexpectedValueException("Only expecting drop-item world actions from the client!");
				}

				return new DropItemAction($this->newItem);
			case self::SOURCE_CREATIVE:
				switch($this->inventorySlot){
					case self::ACTION_MAGIC_SLOT_CREATIVE_DELETE_ITEM:
						$type = CreativeInventoryAction::TYPE_DELETE_ITEM;
						break;
					case self::ACTION_MAGIC_SLOT_CREATIVE_CREATE_ITEM:
						$type = CreativeInventoryAction::TYPE_CREATE_ITEM;
						break;
					default:
						throw new UnexpectedValueException("Unexpected creative action type $this->inventorySlot");

				}

				return new CreativeInventoryAction($this->oldItem, $this->newItem, $type);
			case self::SOURCE_CRAFTING_GRID:
			case self::SOURCE_TODO:
				//These types need special handling.
				switch($this->windowId){
					case self::SOURCE_TYPE_CRAFTING_ADD_INGREDIENT:
					case self::SOURCE_TYPE_CRAFTING_REMOVE_INGREDIENT:
					case self::SOURCE_TYPE_CONTAINER_DROP_CONTENTS: //TODO: this type applies to all fake windows, not just crafting
						return new SlotChangeAction($player->getCraftingGrid(), $this->inventorySlot, $this->oldItem, $this->newItem);
					case self::SOURCE_TYPE_CRAFTING_RESULT:
					case self::SOURCE_TYPE_CRAFTING_USE_INGREDIENT:
						return null;

					// Creds: NukkitX
					case self::SOURCE_TYPE_ENCHANT_INPUT:
					case self::SOURCE_TYPE_ENCHANT_MATERIAL:
					case self::SOURCE_TYPE_ENCHANT_OUTPUT:
						// TODO: Know why tf doesn't the lapiz' deduction get applied server-side
						$inv = $player->getWindow(WindowIds::ENCHANT);
						if(!($inv instanceof EnchantInventory)){
							Main::getInstance()->getLogger()->debug("Player " . $player->getName() . " has no open enchant inventory");

							return null;
						}
						switch($this->windowId){
							case self::SOURCE_TYPE_ENCHANT_INPUT:
								$this->inventorySlot = 0;
								$local = $inv->getItem(0);
								if($local->equals($this->newItem, true, false)){
									$inv->setItem(0, $this->newItem);
								}
								break;
							case self::SOURCE_TYPE_ENCHANT_MATERIAL:
								$this->inventorySlot = 1;
								$inv->setItem(1, $this->oldItem);
								break;
							case self::SOURCE_TYPE_ENCHANT_OUTPUT:
								break;
						}

						return new SlotChangeAction($inv, $this->inventorySlot, $this->oldItem, $this->newItem);

					case self::SOURCE_TYPE_BEACON:
						$inv = $player->getWindow(WindowIds::BEACON);
						if(!($inv instanceof EnchantInventory)){
							Main::getInstance()->getLogger()->debug("Player " . $player->getName() . " has no open beacon inventory");

							return null;
						}
						$this->inventorySlot = 0;

						return new SlotChangeAction($inv, $this->inventorySlot, $this->oldItem, $this->newItem);

					case self::SOURCE_TYPE_ANVIL_INPUT:
					case self::SOURCE_TYPE_ANVIL_MATERIAL:
					case self::SOURCE_TYPE_ANVIL_RESULT:
					case self::SOURCE_TYPE_ANVIL_OUTPUT:
						$inv = $player->getWindow(WindowIds::ANVIL);
						if(!($inv instanceof AnvilInventory)){
							Main::getInstance()->getLogger()->debug("Player " . $player->getName() . " has no open anvil inventory");

							return null;
						}
						switch($this->windowId){
							case self::SOURCE_TYPE_ANVIL_INPUT:
								$this->inventorySlot = 0;
								break;
							case self::SOURCE_TYPE_ANVIL_MATERIAL:
								$this->inventorySlot = 1;
								break;
							case self::SOURCE_TYPE_ANVIL_OUTPUT:
								$inv->sendSlot(2, $inv->getViewers());
								break;
							case self::SOURCE_TYPE_ANVIL_RESULT:
								$this->inventorySlot = 2;
								$cost = $inv->getItem(2)->getNamedTag()->getInt("RepairCost", 1); // todo
								if($player->isSurvival() && $player->getXpLevel() < $cost){
									return null;
								}
								$inv->clear(0);
								if(!($material = $inv->getItem(1))->isNull()){
									$material = clone $material;
									$material->count -= 1;
									$inv->setItem(1, $material);
								}
								$inv->setItem(2, $this->oldItem, false);
								if($player->isSurvival()){
									$player->subtractXpLevels($cost);
								}
						}

						return new SlotChangeAction($inv, $this->inventorySlot, $this->oldItem, $this->newItem);
				}

				//TODO: more stuff
				throw new UnexpectedValueException("Player " . $player->getName() . " has no open container with window ID $this->windowId");
			default:
				throw new UnexpectedValueException("Unknown inventory source type $this->sourceType");
		}
	}
}
