<?php
/**
 * Reminder Model - Matched to actual GarageMinder DB schema
 * 
 * ACTUAL reminders table columns:
 *   id              varchar(64)  PK
 *   vehicle_id      varchar(64)  FK → vehicles.id
 *   service_name    varchar(255)     (NOT "service_type")
 *   title           varchar(255)     (NOT "description")
 *   base_odo        int
 *   base_date       date
 *   interval_miles  int              (recurrence miles)
 *   interval_months int              (recurrence months)
 *   next_odo        int              (NOT "due_mileage")
 *   next_date       date             (NOT "due_date")
 *   notes           text
 *   created_at      datetime
 *   updated_at      datetime
 * 
 * ACTUAL vehicles table (for joins):
 *   current_odo     int              (NOT "odometer")
 */

namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class Reminder
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all reminders for a user (across all vehicles)
     */
    public function getByUser(int $userId): array
    {
        $reminders = $this->db->fetchAll(
            "SELECT r.id, r.vehicle_id, r.service_name, r.title,
                    r.base_odo, r.base_date, r.interval_miles, r.interval_months,
                    r.next_odo, r.next_date, r.notes, r.created_at, r.updated_at,
                    v.year, v.make, v.model, v.name as vehicle_name,
                    v.current_odo
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE v.user_id = ?
             ORDER BY r.next_date ASC, r.next_odo ASC",
            [$userId]
        );

        return array_map([$this, 'formatReminder'], $reminders);
    }

    /**
     * Get reminders for a specific vehicle
     */
    public function getByVehicle(string $vehicleId, int $userId): array
    {
        // Verify ownership
        $vehicle = $this->db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );

        if (!$vehicle) return [];

        $reminders = $this->db->fetchAll(
            "SELECT r.id, r.vehicle_id, r.service_name, r.title,
                    r.base_odo, r.base_date, r.interval_miles, r.interval_months,
                    r.next_odo, r.next_date, r.notes, r.created_at, r.updated_at,
                    v.year, v.make, v.model, v.name as vehicle_name,
                    v.current_odo
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.vehicle_id = ?
             ORDER BY r.next_date ASC, r.next_odo ASC",
            [$vehicleId]
        );

        return array_map([$this, 'formatReminder'], $reminders);
    }

    /**
     * Get single reminder by ID
     * Note: reminder IDs are also varchar(64) strings
     */
    public function getById(string $reminderId, int $userId): ?array
    {
        $reminder = $this->db->fetchOne(
            "SELECT r.id, r.vehicle_id, r.service_name, r.title,
                    r.base_odo, r.base_date, r.interval_miles, r.interval_months,
                    r.next_odo, r.next_date, r.notes, r.created_at, r.updated_at,
                    v.year, v.make, v.model, v.name as vehicle_name,
                    v.current_odo
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.id = ? AND v.user_id = ?",
            [$reminderId, $userId]
        );

        return $reminder ? $this->formatReminder($reminder) : null;
    }

    /**
     * Get due/overdue reminders for a user (all vehicles)
     */
    public function getDueByUser(int $userId, int $windowDays = null): array
    {
        $windowDays = $windowDays ?? REMINDERS_DUE_WINDOW_DAYS;
        $futureDate = date('Y-m-d', strtotime("+{$windowDays} days"));
        $today = date('Y-m-d');

        $reminders = $this->db->fetchAll(
            "SELECT r.id, r.vehicle_id, r.service_name, r.title,
                    r.base_odo, r.base_date, r.interval_miles, r.interval_months,
                    r.next_odo, r.next_date, r.notes, r.created_at, r.updated_at,
                    v.year, v.make, v.model, v.name as vehicle_name,
                    v.current_odo
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE v.user_id = ?
               AND (
                   (r.next_date IS NOT NULL AND r.next_date <= ?)
                   OR (r.next_odo IS NOT NULL AND r.next_odo <= v.current_odo)
               )
             ORDER BY 
               CASE 
                 WHEN r.next_date < ? THEN 0
                 WHEN r.next_odo <= v.current_odo THEN 0
                 ELSE 1
               END ASC,
               r.next_date ASC",
            [$userId, $futureDate, $today]
        );

        return array_map(function($r) use ($today) {
            $formatted = $this->formatReminder($r);
            $formatted['is_overdue'] = $this->isOverdue($r, $today);
            $formatted['urgency'] = $this->calculateUrgency($r, $today);
            return $formatted;
        }, $reminders);
    }

    /**
     * Get due/overdue reminders for a specific vehicle
     */
    public function getDueByVehicle(string $vehicleId, int $userId, int $windowDays = null): array
    {
        $windowDays = $windowDays ?? REMINDERS_DUE_WINDOW_DAYS;
        $futureDate = date('Y-m-d', strtotime("+{$windowDays} days"));
        $today = date('Y-m-d');

        // Verify ownership
        $vehicle = $this->db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );
        if (!$vehicle) return [];

        $reminders = $this->db->fetchAll(
            "SELECT r.id, r.vehicle_id, r.service_name, r.title,
                    r.base_odo, r.base_date, r.interval_miles, r.interval_months,
                    r.next_odo, r.next_date, r.notes, r.created_at, r.updated_at,
                    v.year, v.make, v.model, v.name as vehicle_name,
                    v.current_odo
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.vehicle_id = ?
               AND (
                   (r.next_date IS NOT NULL AND r.next_date <= ?)
                   OR (r.next_odo IS NOT NULL AND r.next_odo <= v.current_odo)
               )
             ORDER BY r.next_date ASC",
            [$vehicleId, $futureDate]
        );

        return array_map(function($r) use ($today) {
            $formatted = $this->formatReminder($r);
            $formatted['is_overdue'] = $this->isOverdue($r, $today);
            $formatted['urgency'] = $this->calculateUrgency($r, $today);
            return $formatted;
        }, $reminders);
    }

    /**
     * Format reminder for API response
     * Maps DB column names → clean API field names
     */
    private function formatReminder(array $r): array
    {
        // Determine if recurring based on whether intervals are set
        $isRecurring = !empty($r['interval_miles']) || !empty($r['interval_months']);

        // Build vehicle display name
        $vehicleDisplayName = $r['vehicle_name'] ?? '';
        if (empty($vehicleDisplayName) && ($r['make'] || $r['model'])) {
            $vehicleDisplayName = trim(($r['year'] ?? '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
        }

        return [
            'id'                => $r['id'],                                    // String ID
            'vehicle_id'        => $r['vehicle_id'],                            // String ID
            'vehicle_name'      => $vehicleDisplayName,
            'service_name'      => $r['service_name'],                          // DB: service_name
            'title'             => $r['title'],                                 // DB: title
            'due_date'          => $r['next_date'] ?? null,                     // DB: next_date → API: due_date
            'due_mileage'       => $r['next_odo'] ? (int) $r['next_odo'] : null, // DB: next_odo → API: due_mileage
            'current_odometer'  => (int) ($r['current_odo'] ?? 0),              // DB: current_odo
            'base_odo'          => $r['base_odo'] ? (int) $r['base_odo'] : null,
            'base_date'         => $r['base_date'] ?? null,
            'is_recurring'      => $isRecurring,                                // Derived from intervals
            'interval_miles'    => $r['interval_miles'] ? (int) $r['interval_miles'] : null,  // DB: interval_miles
            'interval_months'   => $r['interval_months'] ? (int) $r['interval_months'] : null, // DB: interval_months
            'notes'             => $r['notes'] ?? null,
            'created_at'        => $r['created_at'] ?? null,
            'updated_at'        => $r['updated_at'] ?? null,
        ];
    }

    /**
     * Check if reminder is overdue
     */
    private function isOverdue(array $r, string $today): bool
    {
        if (!empty($r['next_date']) && $r['next_date'] < $today) return true;
        if (!empty($r['next_odo']) && (int)$r['next_odo'] <= (int)($r['current_odo'] ?? 0)) return true;
        return false;
    }

    /**
     * Calculate urgency level
     */
    private function calculateUrgency(array $r, string $today): string
    {
        $overdue = $this->isOverdue($r, $today);
        if ($overdue) return 'overdue';

        if (!empty($r['next_date'])) {
            $daysUntil = (strtotime($r['next_date']) - strtotime($today)) / 86400;
            if ($daysUntil <= 7) return 'urgent';
            if ($daysUntil <= 30) return 'upcoming';
        }

        if (!empty($r['next_odo'])) {
            $milesUntil = (int)$r['next_odo'] - (int)($r['current_odo'] ?? 0);
            if ($milesUntil <= 500) return 'urgent';
            if ($milesUntil <= 2000) return 'upcoming';
        }

        return 'normal';
    }
}
