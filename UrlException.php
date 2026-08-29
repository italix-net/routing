<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Routing - Exception
 *
 * @package Italix\Routing
 */

declare(strict_types=1);

namespace Italix\Routing;

use RuntimeException;

/**
 * A URL could not be generated.
 *
 * Every message names the route, and where a parameter is at fault it names the
 * parameter and the pattern it failed. That is the entire point of generating
 * URLs instead of writing them: a wrong link should fail loudly at the moment
 * it is built, not silently render an href that 404s for one user in a
 * fortnight.
 */
final class UrlException extends RuntimeException
{
}
