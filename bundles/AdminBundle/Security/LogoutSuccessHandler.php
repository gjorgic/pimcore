<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Security;

use Pimcore\Bundle\AdminBundle\Security\Event\LogoutListener;
use Symfony\Component\Security\Http\Logout\LogoutSuccessHandlerInterface;

/**
 *
 * @internal
 *
 * @deprecated
 */
class LogoutSuccessHandler extends LogoutListener implements LogoutSuccessHandlerInterface
{
}
