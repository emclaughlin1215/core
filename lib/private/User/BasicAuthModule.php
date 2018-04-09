<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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


namespace OC\User;


use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\IAuthModule;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;

class BasicAuthModule implements IAuthModule {


	/** @var IConfig */
	private $config;
	/** @var IUserManager */
	private $manager;
	/** @var ISession */
	private $session;
	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(IConfig $config, IUserManager $manager, ISession $session, ITimeFactory $timeFactory) {
		$this->config = $config;
		$this->manager = $manager;
		$this->session = $session;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @inheritdoc
	 */
	public function auth(IRequest $request) {
		if (!isset($request->server['PHP_AUTH_USER'], $request->server['PHP_AUTH_PW'])) {
			return null;
		}
		if ($this->session->exists('app_password')) {
			return null;
		}
		$authUser = $request->server['PHP_AUTH_USER'];
		$authPass = $request->server['PHP_AUTH_PW'];
		if ($authUser === '' || $authPass === '') {
			return null;
		}

		// only check password periodically, otherwise we will be hammering
		// external systems like ldap.
		// FIXME ignore session for dav endpoint / only create session on web login

		if ($this->session->exists('last_check_timeout')) {
			$lastCheck = $this->session->get('last_check_timeout');
		} else {
			$lastCheck = 0;
		}
		$now = $this->timeFactory->getTime();
		$last_check_timeout = (int)$this->config->getAppValue('core', 'last_check_timeout', 5);

		if ($lastCheck > ($now - 60 * $last_check_timeout)) {
			// Checked performed recently, nothing to do now
			$user = $this->manager->get($this->session->get('user_id'));
		} else {
			// check uid and password
			$user = $this->manager->checkPassword($authUser, $authPass);
			if ($user instanceof IUser) {
				$this->session->set('last_check_timeout', $now);
				return $user;
			}
			// TODO inject logger & debug log if no user was found.
			// check email and password
			$users = $this->manager->getByEmail($authUser);
			if (count($users) === 1) {
				$this->session->set('last_check_timeout', $now);
				$user = $this->manager->checkPassword($users[0]->getUID(), $authPass);
			}
			// TODO inject logger & debug log if more than one or no user was found.

		}

		if ($user instanceof IUser) {
			return $user;
		}
		throw new \Exception('Invalid credentials');
	}

	/**
	 * @inheritdoc
	 */
	public function getUserPassword(IRequest $request) {
		if (empty($request->server['PHP_AUTH_USER']) || empty($request->server['PHP_AUTH_PW'])) {
			return '';
		}

		return $request->server['PHP_AUTH_PW'];
	}
}
