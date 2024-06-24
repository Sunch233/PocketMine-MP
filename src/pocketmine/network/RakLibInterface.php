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

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\protocol\DataPacket;
use pocketmine\network\protocol\Info as ProtocolInfo;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\PacketReliability;
use raklib\RakLib;
use raklib\server\RakLibServer;
use raklib\server\ServerHandler;
use raklib\server\ServerInstance;
use raklib\utils\InternetAddress;
use function addcslashes;
use function get_class;
use function spl_object_hash;
use function unserialize;
use const PTHREADS_INHERIT_CONSTANTS;

class RakLibInterface implements ServerInstance, AdvancedSourceInterface{
	/**
	 * Sometimes this gets changed when the MCPE-layer protocol gets broken to the point where old and new can't
	 * communicate. It's important that we check this to avoid catastrophes.
	 */
	private const MCPE_RAKNET_PROTOCOL_VERSION = 7;

	private const MCPE_RAKNET_PACKET_ID = "\x8e";

	/** @var Server */
	private $server;

	/** @var Network */
	private $network;

	/** @var RakLibServer */
	private $rakLib;

	/** @var Player[] */
	private $players = [];

	/** @var string[] */
	private $identifiers = [];

	/** @var int[] */
	private $identifiersACK = [];

	/** @var ServerHandler */
	private $interface;

	/** @var SleeperNotifier */
	private $sleeper;

	public function __construct(Server $server){
		$this->server = $server;

		$this->sleeper = new SleeperNotifier();
		$this->rakLib = new RakLibServer(
			$this->server->getLogger(),
			$this->server->getLoader(),
			new InternetAddress($this->server->getIp(), $this->server->getPort(), 4),
			(int) $this->server->getProperty("network.max-mtu-size", 1492),
			self::MCPE_RAKNET_PROTOCOL_VERSION,
			$this->sleeper
		);
		$this->interface = new ServerHandler($this->rakLib, $this);
		$this->start();
	}

	public function start(){
		$this->server->getTickSleeper()->addNotifier($this->sleeper, function() : void{
			$this->process();
		});
		$this->rakLib->start(PTHREADS_INHERIT_CONSTANTS); //HACK: MainLogger needs constants for exception logging
	}

	public function setNetwork(Network $network){
		$this->network = $network;
	}

	public function process() : void{
		while($this->interface->handlePacket()){}

		if(!$this->rakLib->isRunning() and !$this->rakLib->isShutdown()){
			throw new \Exception("RakLib Thread crashed");
		}
	}

	public function closeSession(string $identifier, string $reason) : void{
		if(isset($this->players[$identifier])){
			$player = $this->players[$identifier];
			unset($this->identifiers[spl_object_hash($player)]);
			unset($this->players[$identifier]);
			unset($this->identifiersACK[$identifier]);
			$player->close($player->getLeaveMessage(), $reason);
		}
	}

	public function close(Player $player, string $reason = "unknown reason"){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			unset($this->players[$this->identifiers[$h]]);
			unset($this->identifiersACK[$this->identifiers[$h]]);
			$this->interface->closeSession($this->identifiers[$h], $reason);
			unset($this->identifiers[$h]);
		}
	}

	public function shutdown(){
		$this->server->getTickSleeper()->removeNotifier($this->sleeper);
		$this->interface->shutdown();
	}

	public function emergencyShutdown(){
		$this->server->getTickSleeper()->removeNotifier($this->sleeper);
		$this->interface->emergencyShutdown();
	}

	public function openSession(string $identifier, string $address, int $port, int $clientID) : void{
		$ev = new PlayerCreationEvent($this, Player::class, Player::class, null, $address, $port);
		$this->server->getPluginManager()->callEvent($ev);
		$class = $ev->getPlayerClass();

		/**
		 * @var Player $player
		 * @see Player::__construct()
		 */
		$player = new $class($this, $ev->getClientId(), $ev->getAddress(), $ev->getPort());
		$this->players[$identifier] = $player;
		$this->identifiersACK[$identifier] = 0;
		$this->identifiers[spl_object_hash($player)] = $identifier;
		$this->server->addPlayer($identifier, $player);
	}

	public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags) : void{
		if(isset($this->players[$identifier])){
			try{
				if($packet->buffer !== ""){
					$pk = $this->getPacket($packet->buffer);
					if($pk !== null){
						$pk->decode();
						$this->players[$identifier]->handleDataPacket($pk);
					}
				}
			}catch(\Throwable $e){
				$logger = $this->server->getLogger();
				if(\pocketmine\DEBUG > 1 and isset($pk)){
					$logger->debug("Exception in packet " . get_class($pk) . " 0x" . bin2hex($packet->buffer));
				}
				$logger->logException($e);
			}
		}
	}

	public function blockAddress(string $address, int $timeout = 300){
		$this->interface->blockAddress($address, $timeout);
	}

	public function unblockAddress(string $address){
		$this->interface->unblockAddress($address);
	}

	public function handleRaw(string $address, int $port, string $payload) : void{
		$this->server->handlePacket($address, $port, $payload);
	}

	public function sendRawPacket(string $address, int $port, string $payload){
		$this->interface->sendRaw($address, $port, $payload);
	}

	public function notifyACK(string $identifier, int $identifierACK) : void{

	}

	public function setName(string $name){
		$info = $this->server->getQueryInformation();
		$this->interface->sendOption("name",
			"MCPE;" .
			addcslashes($name, ";") . "\n\n;" .
			ProtocolInfo::CURRENT_PROTOCOL . ";" .
			\pocketmine\MINECRAFT_VERSION_NETWORK . ";" .
			$info->getPlayerCount() . ";" .
			$info->getMaxPlayerCount()
		);
	}

	/**
	 * @param bool $name
	 *
	 * @return void
	 */
	public function setPortCheck($name){
		$this->interface->sendOption("portChecking", $name);
	}

	public function setPacketLimit(int $limit) : void{
		$this->interface->sendOption("packetLimit", $limit);
	}

	public function handleOption(string $option, string $value) : void{
		if($option === "bandwidth"){
			$v = unserialize($value);
			$this->network->addStatistics($v["up"], $v["down"]);
		}
	}

	public function putPacket(Player $player, DataPacket $packet, bool $needACK = false, bool $immediate = true){
		if(isset($this->identifiers[$h = spl_object_hash($player)])){
			$identifier = $this->identifiers[$h];
			if(!$packet->isEncoded){
				$packet->encode();
			}

			$buffer = self::MCPE_RAKNET_PACKET_ID . $packet->buffer;

			$pk = new EncapsulatedPacket();
			$pk->identifierACK = $needACK ? $this->identifiersACK[$identifier]++ : null;
			$pk->buffer = $buffer;
			$pk->reliability = PacketReliability::RELIABLE_ORDERED;
			$pk->orderChannel = 0;

			$this->interface->sendEncapsulated($identifier, $pk, ($needACK ? RakLib::FLAG_NEED_ACK : 0) | ($immediate ? RakLib::PRIORITY_IMMEDIATE : RakLib::PRIORITY_NORMAL));
			return $pk->identifierACK;
		}

		return null;
	}

	public function updatePing(string $identifier, int $pingMS) : void{
		if(isset($this->players[$identifier])){
			$this->players[$identifier]->updatePing($pingMS);
		}
	}

	private function getPacket($buffer){
		$pid = ord($buffer[1]);

		if(($data = $this->network->getPacket($pid)) === null){
			$pid = ord($buffer[0]);
			if(($data = $this->network->getPacket($pid)) === null){
				return null;
			}
			$data->setBuffer($buffer, 1);
			return $data;
		}
		$data->setBuffer($buffer, 2);

		return $data;
	}
}
