<?php
namespace vladimixz;

use Composer\Script\Event;
use Composer\Installer\PackageEvent;

class Installer
{
    public static function postUpdate(Event $event)
    {
        $event->getIO()->write("Post install command is running :)");
    }

    public static function postAutoloadDump(Event $event)
    {
        $event->getIO()->write("Post install command is running :)");
    }

    public static function postPackageInstall(PackageEvent $event)
    {
        $event->getIO()->write("Post install command is running :)");
    }

    public static function warmCache(Event $event)
    {
        $event->getIO()->write("Post install command is running :)");
    }
}
