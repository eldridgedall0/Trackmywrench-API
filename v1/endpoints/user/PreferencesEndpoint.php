<?php
namespace GarageMinder\API\Endpoints\User;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database, Validator};

class PreferencesEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();

        if ($request->getMethod() === 'GET') {
            $this->getPreferences($userId);
        } elseif ($request->getMethod() === 'PUT') {
            $this->updatePreferences($userId, $request);
        } else {
            Response::error('Method not allowed', 405);
        }
    }

    private function getPreferences(int $userId): void
    {
        $db = Database::getInstance();
        $prefs = $db->fetchAll(
            "SELECT preference_key, preference_value FROM api_user_preferences WHERE user_id = ?",
            [$userId]
        );

        $result = [];
        foreach ($prefs as $pref) {
            $result[$pref['preference_key']] = $this->castValue($pref['preference_value']);
        }

        Response::success($result);
    }

    private function updatePreferences(int $userId, Request $request): void
    {
        $body = $request->getBody();
        if (empty($body)) {
            Response::error('No preferences provided.', 400);
            return;
        }

        $db = Database::getInstance();
        $allowedKeys = [
            'auto_sync_enabled', 'sync_frequency', 'sync_on_app_open',
            'background_sync_enabled', 'show_sync_notifications',
            'delete_gps_after_sync', 'sync_over_mobile_data',
            'distance_unit', 'theme', 'notification_reminders',
        ];

        $updated = [];
        foreach ($body as $key => $value) {
            $key = Validator::sanitize($key);
            if (!in_array($key, $allowedKeys)) continue;

            $stringValue = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

            $existing = $db->fetchOne(
                "SELECT id FROM api_user_preferences WHERE user_id = ? AND preference_key = ?",
                [$userId, $key]
            );

            if ($existing) {
                $db->update('api_user_preferences',
                    ['preference_value' => $stringValue],
                    'id = ?', [$existing['id']]
                );
            } else {
                $db->insert('api_user_preferences', [
                    'user_id' => $userId,
                    'preference_key' => $key,
                    'preference_value' => $stringValue,
                ]);
            }

            $updated[$key] = $this->castValue($stringValue);
        }

        Response::success(['updated' => $updated]);
    }

    private function castValue(?string $value)
    {
        if ($value === null) return null;
        if ($value === '1' || $value === 'true') return true;
        if ($value === '0' || $value === 'false') return false;
        if (is_numeric($value)) return strpos($value, '.') !== false ? (float)$value : (int)$value;
        return $value;
    }
}
