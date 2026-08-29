<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class MarketplacePreviewController
{
    public function show(string $screen): Response
    {
        abort_unless(app()->environment('local'), 404);

        $shared = [
            'store' => [
                'hash' => 'demo-store',
                'name' => 'Tamara Demo Store',
                'environment' => 'sandbox',
            ],
            'user' => [
                'name' => 'Marketplace Reviewer',
                'email' => 'reviewer@example.com',
                'isOwner' => true,
            ],
        ];

        return match ($screen) {
            'onboarding' => Inertia::render('Onboarding', $shared),
            'dashboard' => Inertia::render('Dashboard', $shared + [
                'connected' => true,
                'environment' => 'sandbox',
                'stats' => [
                    'totalVolume' => 12750,
                    'payments' => 42,
                    'approvalRate' => 93,
                    'refunds' => 450,
                    'currency' => 'SAR',
                ],
                'recentPayments' => array_slice($this->payments(), 0, 4),
            ]),
            'payments' => Inertia::render('Payments/Index', $shared + [
                'payments' => [
                    'data' => $this->payments(),
                    'current_page' => 1,
                    'last_page' => 1,
                    'prev_page_url' => null,
                    'next_page_url' => null,
                ],
            ]),
            'help' => Inertia::render('Help', $shared + [
                'supportEmail' => 'support@example.com',
                'documentationUrl' => 'https://docs.tamara.co/',
            ]),
            default => abort(404),
        };
    }

    /**
     * Demonstration data used only for local Marketplace screenshots.
     *
     * @return list<array<string, mixed>>
     */
    private function payments(): array
    {
        return [
            $this->payment('preview-1048', '1048', 'Aisha Al-Salem', 1200, 'captured', '30 Aug 2026, 12:42'),
            $this->payment('preview-1047', '1047', 'Omar Hassan', 450, 'authorised', '30 Aug 2026, 11:18'),
            $this->payment('preview-1046', '1046', 'Noura Ali', 890, 'approved', '30 Aug 2026, 09:54'),
            $this->payment('preview-1045', '1045', 'Fahad Ahmed', 275, 'refunded', '29 Aug 2026, 17:06'),
            $this->payment('preview-1044', '1044', 'Sara Ibrahim', 640, 'declined', '29 Aug 2026, 14:31'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(
        string $id,
        string $orderId,
        string $customer,
        float $amount,
        string $status,
        string $createdAt,
    ): array {
        return [
            'id' => $id,
            'orderId' => $orderId,
            'customer' => $customer,
            'amount' => $amount,
            'currency' => 'SAR',
            'status' => $status,
            'createdAt' => $createdAt,
            'reference' => 'tamara-'.$orderId,
        ];
    }
}
