<?php

/*
 *
 * Licensed under the Apache License, Version 2.0.
 *
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * @author Zwuiix
 * @link https://github.com/Zwuiix-cmd/locale-bridge
 *
 */

declare(strict_types=1);

namespace zwuiix\locale\event;

use pocketmine\event\Event;
use pocketmine\lang\Language;
use pocketmine\utils\Utils;
use RuntimeException;
use function is_a;

class LanguageCreationEvent extends Event
{

	/** @phpstan-var class-string<Language> */
	private string $baseClass = Language::class;

	/** @phpstan-var class-string<Language> */
	private string $languageClass = Language::class;

	public function __construct(private readonly string $languageCode) {}

	public function getLanguageCode() : string
	{
		return $this->languageCode;
	}

	/**
	 * Returns the base class that the final language class must extend.
	 *
	 * @phpstan-return class-string<Language>
	 */
	public function getBaseClass() : string
	{
		return $this->baseClass;
	}

	/**
	 * Sets the class that the final language class must extend.
	 * The new base class must be a subclass of the current base class.
	 *
	 * @phpstan-param class-string<Language> $class
	 */
	public function setBaseClass(string $class) : void
	{
		if (!is_a($class, $this->baseClass, true)) {
			throw new RuntimeException("Base class $class must extend " . $this->baseClass);
		}

		$this->baseClass = $class;
	}

	/**
	 * Returns the class that will be instantiated to create the language after the event.
	 *
	 * @phpstan-return class-string<Language>
	 */
	public function getLanguageClass() : string
	{
		return $this->languageClass;
	}

	/**
	 * Sets the class that will be instantiated to create the language after the event.
	 * The class must not be abstract and must extend the base class.
	 *
	 * @phpstan-param class-string<Language> $class
	 */
	public function setLanguageClass(string $class) : void
	{
		Utils::testValidInstance($class, $this->baseClass);
		$this->languageClass = $class;
	}
}
