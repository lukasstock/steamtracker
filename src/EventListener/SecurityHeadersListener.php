<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security-related HTTP headers to every main request response.
 *
 * X-Frame-Options        - prevents clickjacking by blocking cross-origin iframes
 * X-Content-Type-Options - stops browsers from MIME-sniffing response content
 * Referrer-Policy        - only sends the origin on cross-site requests, not the full URL
 * Permissions-Policy     - explicitly disables sensitive browser APIs we don't use
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
class SecurityHeadersListener
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
