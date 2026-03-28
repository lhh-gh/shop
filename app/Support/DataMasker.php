<?php

namespace App\Support;

class DataMasker
{
    /**
     * Mask middle 4 digits of phone number
     *
     * @param string $phone
     * @return string
     */
    public static function phone(string $phone): string
    {
        // Remove common formatting characters
        $cleaned = preg_replace('/[\s\-\(\)\.]+/', '', $phone);

        // Remove country code if present (+ at start)
        $cleaned = ltrim($cleaned, '+');

        // Check if it's a valid phone number (at least 10 digits)
        if (!preg_match('/^\d{10,}$/', $cleaned)) {
            return $phone;
        }

        // Extract first 3 and last 4 digits
        $first = substr($cleaned, 0, 3);
        $last = substr($cleaned, -4);

        return $first . '****' . $last;
    }

    /**
     * Mask email username part, keep domain
     *
     * @param string $email
     * @return string
     */
    public static function email(string $email): string
    {
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        // Split email into username and domain
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $username = $parts[0];
        $domain = $parts[1];

        // Show first character + *** + domain
        $first = substr($username, 0, 1);

        return $first . '***@' . $domain;
    }

    /**
     * Mask IP address (last octet for IPv4, last 4 groups for IPv6)
     *
     * @param string $ip
     * @return string
     */
    public static function ip(string $ip): string
    {
        // Check if it's IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return implode('.', array_slice($parts, 0, 3)) . '.*';
        }

        // Check if it's IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Expand IPv6 to full format for consistent handling
            $expanded = inet_pton($ip);
            if ($expanded === false) {
                return $ip;
            }

            // Convert back to standard format
            $ip = inet_ntop($expanded);

            // Split by colon
            $parts = explode(':', $ip);

            // Keep first 4 groups, mask the rest
            if (count($parts) >= 4) {
                return implode(':', array_slice($parts, 0, 4)) . ':****';
            }

            return $ip;
        }

        // Invalid IP format
        return $ip;
    }

    /**
     * Mask token showing first 8 and last 4 characters
     *
     * @param string $token
     * @return string
     */
    public static function token(string $token): string
    {
        // Return original if too short (less than 13 chars)
        if (strlen($token) < 13) {
            return $token;
        }

        $first = substr($token, 0, 8);
        $last = substr($token, -4);

        return $first . '...' . $last;
    }
}
