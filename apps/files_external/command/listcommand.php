<?php
/**
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_External\Command;

use OC\Core\Command\Base;
use OCA\Files_external\Lib\StorageConfig;
use OCA\Files_external\Service\GlobalStoragesService;
use OCA\Files_external\Service\UserStoragesService;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Base {
	/**
	 * @var GlobalStoragesService
	 */
	private $globalService;

	/**
	 * @var UserStoragesService
	 */
	private $userService;

	/**
	 * @var IUserSession
	 */
	private $userSession;

	/**
	 * @var IUserManager
	 */
	private $userManager;

	function __construct(GlobalStoragesService $globalService, UserStoragesService $userService, IUserSession $userSession, IUserManager $userManager) {
		parent::__construct();
		$this->globalService = $globalService;
		$this->userService = $userService;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
	}

	protected function configure() {
		$this
			->setName('files_external:list')
			->setDescription('List configured mounts')
			->addArgument(
				'user_id',
				InputArgument::OPTIONAL,
				'user id to list the personal mounts for, if no user is provided admin mounts will be listed'
			)->addOption(
				'show-password',
				null,
				InputOption::VALUE_NONE,
				'show passwords and secrets'
			)->addOption(
				'full',
				null,
				InputOption::VALUE_NONE,
				'don\'t truncate long values in table output'
			);
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('user_id');
		if (!empty($userId)) {
			$user = $this->userManager->get($userId);
			if (is_null($user)) {
				$output->writeln("<error>user $userId not found</error>");
				return;
			}
			$this->userSession->setUser($user);
			$storageService = $this->userService;
		} else {
			$storageService = $this->globalService;
		}

		/** @var  $mounts StorageConfig[] */
		$mounts = $storageService->getAllStorages();

		if (count($mounts) === 0) {
			if ($userId) {
				$output->writeln("<info>No mounts configured by $userId</info>");
			} else {
				$output->writeln("<info>No admin mounts configured</info>");
			}
			return;
		}

		$headers = ['Mount ID', 'Mount Point', 'Storage', 'Authentication Type', 'Configuration', 'Options'];

		if (!$userId) {
			$headers[] = 'Applicable Users';
			$headers[] = 'Applicable Groups';
		}

		if (!$input->getOption('show-password')) {
			$hideKeys = ['password', 'refresh_token', 'token', 'client_secret', 'public_key', 'private_key'];
			foreach ($mounts as $mount) {
				$config = $mount->getBackendOptions();
				foreach ($config as $key => $value) {
					if (in_array($key, $hideKeys)) {
						$mount->setBackendOption($key, '***');
					}
				}
			}
		}

		$outputType = $input->getOption('output');
		if ($outputType === self::OUTPUT_FORMAT_JSON || $outputType === self::OUTPUT_FORMAT_JSON_PRETTY) {
			$keys = array_map(function ($header) {
				return strtolower(str_replace(' ', '_', $header));
			}, $headers);

			$pairs = array_map(function (StorageConfig $config) use ($keys, $userId) {
				$values = [
					$config->getId(),
					$config->getMountPoint(),
					$config->getBackend()->getStorageClass(),
					$config->getAuthMechanism()->getScheme(),
					$config->getBackendOptions(),
					$config->getMountOptions()
				];
				if (!$userId) {
					$values[] = $config->getApplicableUsers();
					$values[] = $config->getApplicableGroups();
				}

				return array_combine($keys, $values);
			}, $mounts);
			if ($outputType === self::OUTPUT_FORMAT_JSON) {
				$output->writeln(json_encode(array_values($pairs)));
			} else {
				$output->writeln(json_encode(array_values($pairs), JSON_PRETTY_PRINT));
			}
		} else {
			$full = $input->getOption('full');
			$defaultMountOptions = [
				'encrypt' => true,
				'previews' => true,
				'filesystem_check_changes' => 1
			];
			$rows = array_map(function (StorageConfig $config) use ($userId, $defaultMountOptions, $full) {
				$storageConfig = $config->getBackendOptions();
				$keys = array_keys($storageConfig);
				$values = array_values($storageConfig);

				if (!$full) {
					$values = array_map(function ($value) {
						if (is_string($value) && strlen($value) > 32) {
							return substr($value, 0, 6) . '...' . substr($value, -6, 6);
						} else {
							return $value;
						}
					}, $values);
				}

				$configStrings = array_map(function ($key, $value) {
					return $key . ': ' . json_encode($value);
				}, $keys, $values);
				$configString = implode(', ', $configStrings);

				$mountOptions = $config->getMountOptions();
				// hide defaults
				foreach ($mountOptions as $key => $value) {
					if ($value === $defaultMountOptions[$key]) {
						unset($mountOptions[$key]);
					}
				}
				$keys = array_keys($mountOptions);
				$values = array_values($mountOptions);

				$optionsStrings = array_map(function ($key, $value) {
					return $key . ': ' . json_encode($value);
				}, $keys, $values);
				$optionsString = implode(', ', $optionsStrings);

				$values = [
					$config->getId(),
					$config->getMountPoint(),
					$config->getBackend()->getText(),
					$config->getAuthMechanism()->getText(),
					$configString,
					$optionsString
				];

				if (!$userId) {
					$applicableUsers = implode(', ', $config->getApplicableUsers());
					$applicableGroups = implode(', ', $config->getApplicableGroups());
					if ($applicableUsers === '' && $applicableGroups === '') {
						$applicableUsers = 'All';
					}
					$values[] = $applicableUsers;
					$values[] = $applicableGroups;
				}

				return $values;
			}, $mounts);

			$table = new Table($output);
			$table->setHeaders($headers);
			$table->setRows($rows);
			$table->render();
		}
	}
}
