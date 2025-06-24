<?php

namespace TelegramMusicBot\Controllers;

class AuthController
{
    private array $adminUserIds;

    public function __construct()
    {
        if (!defined('ADMIN_USER_IDS') || !is_array(ADMIN_USER_IDS)) {
            error_log("ADMIN_USER_IDS is not defined or not an array in config.php.");
            $this->adminUserIds = [];
        } else {
            $this->adminUserIds = ADMIN_USER_IDS;
        }
    }

    /**
     * Checks if a given user ID belongs to an admin.
     *
     * @param int $userId The user ID to check.
     * @return bool True if the user is an admin, false otherwise.
     */
    public function isAdmin(int $userId): bool
    {
        if (empty($this->adminUserIds)) {
            // No admins defined, so no one is an admin.
            // Log this situation as it might be a configuration oversight.
            // error_log("Warning: No admin user IDs configured. All isAdmin checks will return false.");
            return false;
        }
        return in_array($userId, $this->adminUserIds);
    }

    /**
     * Get the list of admin user IDs.
     *
     * @return array
     */
    public function getAdminIds(): array
    {
        return $this->adminUserIds;
    }
}
