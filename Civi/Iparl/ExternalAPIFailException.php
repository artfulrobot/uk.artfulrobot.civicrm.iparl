<?php
namespace Civi\Iparl;

/**
 * Thrown when we require an action/petition's title and either we could not
 * load the resource from the iParl API, or the ID was not found within the
 * things we loaded.
 */
class ExternalAPIFailException extends \Exception {
}
