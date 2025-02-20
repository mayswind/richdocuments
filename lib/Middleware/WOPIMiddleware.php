<?php
/*
 * @copyright Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 * @copyright Copyright (c) 2017 Lukas Reschke <lukas@statuscode.ch>
 *
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace OCA\Richdocuments\Middleware;

use OCA\Richdocuments\AppInfo\Application;
use OCA\Richdocuments\Controller\WopiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IRequest;

class WOPIMiddleware extends Middleware {
	/** @var IConfig */
	private $config;
	/** @var IRequest */
	private $request;

	public function __construct(IConfig $config, IRequest $request) {
		$this->config = $config;
		$this->request = $request;
	}

	public function beforeController($controller, $methodName) {
		parent::beforeController($controller, $methodName);

		if ($controller instanceof WopiController && !$this->isWOPIAllowed()) {
			throw new NotPermittedException();
		}
	}

	public function afterException($controller, $methodName, \Exception $exception): Response {
		if ($exception instanceof NotPermittedException && $controller instanceof WopiController) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		throw $exception;
	}

	public function isWOPIAllowed(): bool {
		$allowedRanges = $this->config->getAppValue(Application::APPNAME, 'wopi_allowlist');
		if ($allowedRanges === '') {
			return true;
		}
		$allowedRanges = explode(',', $allowedRanges);

		$userIp = $this->request->getRemoteAddress();
		foreach ($allowedRanges as $range) {
			if ($this->matchCidr($userIp, $range)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @copyright https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5/594134#594134
	 * @copyright (IPv4) https://stackoverflow.com/questions/594112/matching-an-ip-to-a-cidr-mask-in-php-5/594134#594134
	 * @copyright (IPv6) MW. https://stackoverflow.com/questions/7951061/matching-ipv6-address-to-a-cidr-subnet via
	 */
	private function matchCidr(string $ip, string $range): bool {
		list($subnet, $bits) = array_pad(explode('/', $range), 2, null);
		if ($bits === null) {
			$bits = 32;
		}
		$bits = (int)$bits;

		if ($this->isIpv4($ip) && $this->isIpv4($subnet)) {
			$mask = -1 << (32 - $bits);

			$ip = ip2long($ip);
			$subnet = ip2long($subnet);
			$subnet &= $mask;
			return ($ip & $mask) === $subnet;
		}

		if ($this->isIpv6($ip) && $this->isIPv6($subnet)) {
			$subnet = inet_pton($subnet);
			$ip = inet_pton($ip);

			$binMask = str_repeat("f", $bits / 4);
			switch ($bits % 4) {
				case 0:
					break;
				case 1:
					$binMask .= "8";
					break;
				case 2:
					$binMask .= "c";
					break;
				case 3:
					$binMask .= "e";
					break;
			}

			$binMask = str_pad($binMask, 32, '0');
			$binMask = pack("H*", $binMask);

			if (($ip & $binMask) === $subnet) {
				return true;
			}
		}
		return false;
	}

	private function isIpv4($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}

	private function isIpv6($ip) {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
	}
}
