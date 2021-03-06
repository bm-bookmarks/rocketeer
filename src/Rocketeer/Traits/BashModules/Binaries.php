<?php
/*
 * This file is part of Rocketeer
 *
 * (c) Maxime Fabre <ehtnam6@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Rocketeer\Traits\BashModules;

/**
 * Handles findingand calling binaries
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
class Binaries extends Filesystem
{
	////////////////////////////////////////////////////////////////////
	/////////////////////////////// BINARIES ///////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to PHP
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	public function php($command = null)
	{
		$php = $this->which('php');

		return trim($php. ' ' .$command);
	}

	/**
	 * Prefix a command with the right path to Composer
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	public function composer($command = null)
	{
		$composer = $this->which('composer', $this->releasesManager->getCurrentReleasePath().'/composer.phar');

		// Prepend PHP command
		if (strpos($composer, 'composer.phar') !== false) {
			$composer = $this->php($composer);
		}

		return trim($composer. ' ' .$command);
	}

	// Artisan
	////////////////////////////////////////////////////////////////////

	/**
	 * Prefix a command with the right path to Artisan
	 *
	 * @param string $command
	 *
	 * @return string
	 */
	public function artisan($command = null)
	{
		$artisan = $this->which('artisan') ?: 'artisan';

		return $this->php($artisan. ' ' .$command);
	}

	/**
	 * Run any outstanding migrations
	 *
	 * @param boolean $seed Whether the database should also be seeded
	 *
	 * @return string
	 */
	public function runMigrations($seed = false)
	{
		$seed = $seed ? ' --seed' : null;
		$this->command->comment('Running outstanding migrations');

		return $this->runForCurrentRelease($this->artisan('migrate'.$seed));
	}

	/**
	 * Seed the database
	 *
	 * @param string $class A class to seed
	 *
	 * @return string
	 */
	public function seed($class = null)
	{
		$class = $class ? ' --class="'.$class.'"' : null;

		return $this->runForCurrentRelease($this->artisan('db:seed'.$class));
	}

	// PHPUnit
	////////////////////////////////////////////////////////////////////

	/**
	 * Run the application's tests
	 *
	 * @param string $arguments Additional arguments to pass to PHPUnit
	 *
	 * @return boolean
	 */
	public function runTests($arguments = null)
	{
		// Look for PHPUnit
		$phpunit = $this->which('phpunit', $this->releasesManager->getCurrentReleasePath().'/vendor/bin/phpunit');
		if (!$phpunit) {
			return true;
		}

		// Run PHPUnit
		$this->command->info('Running tests...');
		$output = $this->runForCurrentRelease(array(
			$phpunit. ' --stop-on-failure '.$arguments,
		));

		return $this->checkStatus('Tests failed', $output, 'Tests passed successfully');
	}

	// Composer
	////////////////////////////////////////////////////////////////////

	/**
	 * Run Composer on the folder
	 *
	 * @param boolean $force
	 *
	 * @return string
	 */
	public function runComposer($force = false)
	{
		// Find Composer
		$composer = $this->composer();
		if (!$composer) {
			return true;
		}

		// Check for Composer file
		$dependencies = $this->releasesManager->getCurrentReleasePath().'/composer.json';
		if (!$this->fileExists($dependencies) and !$force) {
			return true;
		}

		// Get the Composer commands to run
		$tasks = $this->rocketeer->getOption('remote.composer');
		$tasks = (array) $tasks($this);
		if (empty($tasks)) {
			return true;
		}

		// Run commands
		$this->command->comment('Installing Composer dependencies');
		$this->runForCurrentRelease($tasks);

		return $this->checkStatus('Composer could not install dependencies');
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////////// HELPERS ////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get a binary
	 *
	 * @param  string $binary    The name of the binary
	 * @param  string $fallback  A fallback location
	 *
	 * @return string
	 */
	public function which($binary, $fallback = null)
	{
		$location  = false;
		$locations = array(
			array($this->server,    'getValue',    'paths.'.$binary),
			array($this->rocketeer, 'getPath',     $binary),
			array($this,            'runSilently', 'which '.$binary),
		);

		// Add fallback if provided
		if ($fallback) {
			$locations[] = array($this, 'runSilently', 'which '.$fallback);
		}

		// Add command prompt if possible
		if ($this->hasCommand()) {
			$prompt      = $binary. ' could not be found, please enter the path to it';
			$locations[] = array($this->command, 'ask', $prompt);
		}

		// Look in all the locations
		$tryout = 0;
		while (!$location and array_key_exists($tryout, $locations)) {
			list($object, $method, $argument) = $locations[$tryout];

			$location = $object->$method($argument);
			$tryout++;
		}

		// Store found location
		$this->server->setValue('paths.'.$binary, $location);

		return $location ?: false;
	}
}
