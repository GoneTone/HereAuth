<?php

/*
 * HereAuth
 *
 * Copyright (C) 2016 PEMapModder
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PEMapModder
 */

namespace HereAuth\User;

use HereAuth\Event\HereAuthAuthenticationEvent;
use HereAuth\Event\HereAuthLoginEvent;
use HereAuth\Event\HereAuthRegistrationCreationEvent;
use HereAuth\Event\HereAuthRegistrationEvent;
use HereAuth\HereAuth;
use HereAuth\User\Registration\Registration;
use pocketmine\entity\Effect;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class User{
	const STATE_PLAYING = 0;
	const STATE_REGISTERING = 1;
	const STATE_PENDING_LOGIN = 2;

	/** @type HereAuth */
	private $main;
	/** @type Player */
	private $player;
	/** @type AccountInfo */
	private $accountInfo;
	/** @type int */
	private $state;
	/** @type Registration */
	private $registration;
	/** @type int */
	private $loginAttempts = 0;
	/** @type float */
	private $loadTime;
	/** @type Effect|bool|null */
	private $revInvis = null;
	/** @type string|null */
	private $origNametag = null;
	/** @type Position|null */
	public $origPos = null;

	/** @type string|null */
	private $changepwHash = null;

	public function __construct(HereAuth $main, Player $player, AccountInfo $info){
		$this->loadTime = microtime(true);
		$this->main = $main;
		$this->player = $player;
		$this->accountInfo = $info;
		if(!$info->passwordHash){
			$main->getDataBase()->passesLimit($player->getAddress(), $main->getConfig()->getNested("Registration.RateLimit.Accounts", 3), $main->getConfig()->getNested("Registration.RateLimit.Days", 30) * 86400, $player->getId());
			if(!$main->getConfig()->getNested("ForceRegister.Enabled", true)){ // no registration involved
				$this->onAuth();
				$reminder = $main->getConfig()->getNested("ForceRegister.Reminder", "");
				if(strlen($reminder) > 0){
					$player->sendMessage($reminder);
				}
				return;
			}
			$this->startRegistration();
			$this->initAppearance();

			return;
		}
		if($info->opts->autoSecret and $player->getClientSecret() === $info->lastSecret and $this->callLogin(HereAuthLoginEvent::METHOD_CLIENT_SECRET)){
			$this->main->getAuditLogger()->logLogin(strtolower($player->getName()), $player->getAddress(), "secret");
			$this->onAuth();
			return;
		}
		if($info->opts->autoIp and $player->getAddress() === $info->lastIp and $this->callLogin(HereAuthLoginEvent::METHOD_IP)){
			$this->main->getAuditLogger()->logLogin(strtolower($player->getName()), $player->getAddress(), "ip");
			$this->onAuth();
			return;
		}
		if($info->opts->autoUuid and $player->getUniqueId()->toBinary() === $info->lastUuid and $this->callLogin(HereAuthLoginEvent::METHOD_UUID)){
			$this->main->getAuditLogger()->logLogin(strtolower($player->getName()), $player->getAddress(), "uuid");
			$this->onAuth();
			return;
		}
		$this->state = self::STATE_PENDING_LOGIN;
		$this->player->sendMessage($main->getConfig()->getNested("Messages.Login.Query", "Please login"));
		$this->initAppearance();
	}

	public function startRegistration(){
		$this->state = self::STATE_REGISTERING;
		$this->main->getServer()->getPluginManager()->callEvent($ev = new HereAuthRegistrationCreationEvent($this));
		$this->registration = $ev->getRegistration();
		$this->getPlayer()->sendMessage($this->getMain()->getConfig()->getNested("Messages.Register.ImplicitRegister", "This server uses HereAuth to protect your account."));
		$this->registration->init();
	}

	/**
	 * @internal $DEATH_THREATS Do not use this method from other plugins.
	 */
	public function onRegistrationCompleted(){
		$this->main->getServer()->getPluginManager()->callEvent(new HereAuthRegistrationEvent($this));
		$this->main->getAuditLogger()->logRegister(strtolower($this->player->getName()), $this->player->getAddress());
		$this->getPlayer()->sendMessage($this->getMain()->getConfig()->getNested("Messages.Register.Completion", "registered"));
		$this->accountInfo->registerTime = time();
		$this->onAuth();
		$this->main->getLogger()->debug("Registered HereAuth account '{$this->getPlayer()->getName()}'");
	}

	public function checkMultiFactor(){
		if($this->accountInfo->opts->multiIp){
			if($this->player->getAddress() !== $this->accountInfo->lastIp){
				$this->main->getAuditLogger()->logFactor(strtolower($this->player->getName()), "ip", $this->player->getAddress());
				$this->player->kick("Incorrect IP address!", false);
				return false;
			}
		}
		if($this->accountInfo->opts->multiSkin){
			if(HereAuth::hash($this->player->getSkinData(), $this->player->getSkinName()) !== $this->accountInfo->lastSkinHash){
				$this->main->getAuditLogger()->logFactor(strtolower($this->player->getName()), "skin", $this->player->getSkinName() . ":" . base64_encode($this->player->getSkinData()));
				$this->player->kick("Incorrect skin!", false);
				return false;
			}
		}
		return true;
	}

	public function finalize(){
		if($this->state === self::STATE_PLAYING){
			$this->save();
		}
		if($this->origPos !== null){
			$this->player->teleport($this->origPos);
		}
	}

	public function save(){
		$this->main->getDataBase()->saveData($this->accountInfo);
	}

	public function onAuth(){
		$this->main->getServer()->getPluginManager()->callEvent(new HereAuthAuthenticationEvent($this));
		$this->state = self::STATE_PLAYING;
		$this->loginAttempts = 0;
		$this->accountInfo->lastUuid = $this->getPlayer()->getUniqueId()->toBinary();
		$this->accountInfo->lastLogin = time();
		$this->accountInfo->lastSecret = $this->getPlayer()->getClientSecret();
		$this->accountInfo->lastSkinHash = HereAuth::hash($this->getPlayer()->getSkinData(), $this->getPlayer()->getSkinName());
		$this->accountInfo->lastIp = $this->getPlayer()->getAddress();
		if($this->accountInfo->passwordHash){
			$this->player->sendMessage("You have been authenticated.");
		}
		$this->player->getInventory()->sendContents($this->player);
		$this->player->getInventory()->sendArmorContents($this->player);
		if($this->origPos instanceof Position){
			$this->getPlayer()->teleport($this->origPos);
			$this->origPos = null;
		}
		$this->save();
		$this->revertAppearance();
	}

	public function onMessage(PlayerCommandPreprocessEvent $event){
		$message = $event->getMessage();
		$hash = HereAuth::hash($message, $this->getPlayer());
		if($this->state === self::STATE_PENDING_LOGIN){
			if($this->accountInfo->testPassword($this->main, $message) and $this->callLogin(HereAuthLoginEvent::METHOD_PASSWORD)){
				$this->main->getAuditLogger()->logLogin(strtolower($this->player->getName()), $this->player->getAddress(), "password");
				$this->onAuth();
			}else{
				$this->main->getAuditLogger()->logInvalid(strtolower($this->player->getName()), $this->player->getAddress());
				$this->loginAttempts++;
				$chances = $this->main->getConfig()->getNested("Login.MaxAttempts", 5);
				$left = $chances - $this->loginAttempts;
				if($left <= 0){
					$this->getPlayer()->kick("Failed to login in $chances attempts", false);
				}
				$msg = $this->getMain()->getConfig()->getNested("Messages.Login.WrongPass", "wrong pass");
				$msg = str_replace('$CHANCES', $left, $msg);
				$this->getPlayer()->sendMessage($msg);
			}
			$event->setCancelled();
			$event->setMessage("");
		}elseif($this->state === self::STATE_PLAYING){
			if($hash === $this->accountInfo->passwordHash and $this->getMain()->getConfig()->getNested("BlockPasswordChat", true)){
				$event->setCancelled();
				$event->setMessage("");
				$this->getPlayer()->sendMessage($this->getMain()->getConfig()->getNested("Messages.Chat.DirectPass", "Don't tell your password"));
			}
		}elseif($this->state === self::STATE_REGISTERING){
			$this->registration->handle($message);
			$event->setCancelled();
			$event->setMessage("");
		}
	}

	public function getState(){
		return $this->state;
	}

	public function isPlaying(){
		return $this->state === self::STATE_PLAYING;
	}

	public function isRegistering(){
		return $this->state === self::STATE_REGISTERING;
	}

	public function isLoggingIn(){
		return $this->state === self::STATE_PENDING_LOGIN;
	}

	/**
	 * @return HereAuth
	 */
	public function getMain(){
		return $this->main;
	}

	/**
	 * @return Player
	 */
	public function getPlayer(){
		return $this->player;
	}

	/**
	 * @return Registration
	 */
	public function getRegistration(){
		return $this->registration;
	}

	/**
	 * @return AccountInfo
	 */
	public function getAccountInfo(){
		return $this->accountInfo;
	}

	/**
	 * Returns the microtime when this user is created (data is loaded)
	 *
	 * @return float
	 */
	public function getLoadTime(){
		return $this->loadTime;
	}

	/**
	 * @return string|null
	 */
	public function getChangepwHash(){
		return $this->changepwHash;
	}

	/**
	 * @param string|null $changepwHash
	 */
	public function setChangepwHash($changepwHash){
		$this->changepwHash = $changepwHash;
	}

	/**
	 * @return bool
	 */
	public function hasRegistered(){
		return (bool) $this->accountInfo->passwordHash;
	}

	protected function callLogin($method){
		$this->main->getServer()->getPluginManager()->callEvent($ev = new HereAuthLoginEvent($this, $method));
		return !$ev->isCancelled();
	}

	public function lock(){
		if(!$this->accountInfo->passwordHash){
			return false;
		}
		$this->state = self::STATE_PENDING_LOGIN;
		$this->loginAttempts = 0;
		$this->getPlayer()->sendMessage("You have locked out.");
		return true;
	}

	protected function initAppearance(){
		if($this->main->getConfig()->getNested("Appearance.Invisible", false)){
			$this->makeInvisible();
		}
		$this->origNametag = $nt = $this->getPlayer()->getNameTag();
		$nt = $this->main->getConfig()->getNested("Appearance.PrependNametag", TextFormat::GRAY . "[") .
			$nt . $this->main->getConfig()->getNested("Appearance.AppendNametag", "]");
		$this->getPlayer()->setNameTag($nt);
	}

	protected function makeInvisible(){
		$invis = $this->getPlayer()->getEffect(Effect::INVISIBILITY);
		if($invis !== null){
			$this->revInvis = clone $invis;
			$this->getPlayer()->removeEffect(Effect::INVISIBILITY);
		}
		$this->revInvis = true;
		$this->getPlayer()->addEffect(
			Effect::getEffect(Effect::INVISIBILITY)
				->setDuration(0x7FFFFFFF)
				->setVisible(false)
		);
	}

	protected function revertAppearance(){
		$this->makeVisible();
		if($this->origNametag !== null){
			$this->getPlayer()->setNameTag($this->origNametag);
			$this->origNametag = null;
		}
	}

	protected function makeVisible(){
		if($this->revInvis !== null){
			$this->getPlayer()->removeEffect(Effect::INVISIBILITY);
			if($this->revInvis instanceof Effect){
				$this->getPlayer()->addEffect($this->revInvis);
			}
			$this->revInvis = null;
		}
	}
}
