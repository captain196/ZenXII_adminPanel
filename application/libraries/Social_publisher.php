<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Social Publisher Library
 *
 * Mock API publisher with adapter pattern.
 * Each platform has a private _publish_{platform}() method.
 * Currently all platforms use mock mode (95% success rate, fake ref IDs).
 * To go live: replace mock logic inside each adapter method — no controller changes needed.
 */
class Social_publisher
{
    /** Supported platforms */
    const PLATFORMS = ['facebook', 'instagram', 'whatsapp', 'linkedin', 'telegram'];

    /** Platform display labels */
    const PLATFORM_LABELS = [
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp'  => 'WhatsApp',
        'linkedin'  => 'LinkedIn',
        'telegram'  => 'Telegram',
    ];

    /** Platform FA icons */
    const PLATFORM_ICONS = [
        'facebook'  => 'fa-facebook',
        'instagram' => 'fa-instagram',
        'whatsapp'  => 'fa-whatsapp',
        'linkedin'  => 'fa-linkedin',
        'telegram'  => 'fa-telegram',
    ];

    /** Platform brand colors */
    const PLATFORM_COLORS = [
        'facebook'  => '#1877F2',
        'instagram' => '#E4405F',
        'whatsapp'  => '#25D366',
        'linkedin'  => '#0A66C2',
        'telegram'  => '#26A5E4',
    ];

    /**
     * Publish a post to a single platform.
     *
     * @param string $platform  One of self::PLATFORMS
     * @param array  $config    Decoded account config (page_id, token, etc.)
     * @param array  $post      Post data (title, content, media, etc.)
     * @return array {success: bool, ref_id: string|null, error: string|null}
     */
    public function publish(string $platform, array $config, array $post): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return ['success' => false, 'ref_id' => null, 'error' => "Unsupported platform: {$platform}"];
        }

        $method = "_publish_{$platform}";
        return $this->$method($config, $post);
    }

    /**
     * Test connection to a platform account.
     *
     * @param string $platform
     * @param array  $config
     * @return array {success: bool, message: string}
     */
    public function test_connection(string $platform, array $config): array
    {
        if (!in_array($platform, self::PLATFORMS, true)) {
            return ['success' => false, 'message' => "Unsupported platform: {$platform}"];
        }

        // Mock: 90% success
        if (mt_rand(1, 100) <= 90) {
            return [
                'success' => true,
                'message' => self::PLATFORM_LABELS[$platform] . ' connection verified successfully.',
            ];
        }

        return [
            'success' => false,
            'message' => 'Connection test failed. Please check your credentials and try again.',
        ];
    }

    // ── Platform adapters (mock implementations) ─────────────────────

    private function _publish_facebook(array $config, array $post): array
    {
        return $this->_mock_publish('facebook', $config, $post);
    }

    private function _publish_instagram(array $config, array $post): array
    {
        return $this->_mock_publish('instagram', $config, $post);
    }

    private function _publish_whatsapp(array $config, array $post): array
    {
        return $this->_mock_publish('whatsapp', $config, $post);
    }

    private function _publish_linkedin(array $config, array $post): array
    {
        return $this->_mock_publish('linkedin', $config, $post);
    }

    private function _publish_telegram(array $config, array $post): array
    {
        return $this->_mock_publish('telegram', $config, $post);
    }

    // ── Mock engine ──────────────────────────────────────────────────

    /**
     * Simulate publishing with 95% success rate.
     */
    private function _mock_publish(string $platform, array $config, array $post): array
    {
        // Simulate network latency
        usleep(mt_rand(50000, 200000)); // 50-200ms

        if (mt_rand(1, 100) <= 95) {
            $ref_id = strtoupper(substr($platform, 0, 2)) . '_' . bin2hex(random_bytes(8));
            return [
                'success' => true,
                'ref_id'  => $ref_id,
                'error'   => null,
            ];
        }

        $errors = [
            'Rate limit exceeded. Please try again later.',
            'Authentication token expired.',
            'Content policy violation detected.',
            'Media upload failed — file too large.',
            'Network timeout — platform API unreachable.',
        ];

        return [
            'success' => false,
            'ref_id'  => null,
            'error'   => $errors[array_rand($errors)],
        ];
    }
}
