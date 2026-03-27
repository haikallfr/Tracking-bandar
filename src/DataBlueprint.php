<?php

declare(strict_types=1);

final class DataBlueprint
{
    public static function sources(): array
    {
        return [
            [
                'name' => 'Stockbit Market Detector',
                'status' => 'ready',
                'requires_login' => true,
                'purpose' => 'Broker summary 7D, turnover, volume, buyer/seller concentration.',
                'collector' => 'src/StockbitClient.php',
                'notes' => 'Sudah dipakai oleh radar saat ini.',
            ],
            [
                'name' => 'Stockbit Company Price Feed',
                'status' => 'partial',
                'requires_login' => true,
                'purpose' => 'Historikal close/minutely prices untuk price action dan momentum.',
                'collector' => 'belum dipasang penuh',
                'notes' => 'Endpoint terdeteksi dan bisa diakses dengan login yang sama, tetapi masih perlu pemetaan parameter/timeframe yang lebih lengkap.',
            ],
            [
                'name' => 'IDX Symbol Universe Mirror',
                'status' => 'ready',
                'requires_login' => false,
                'purpose' => 'Daftar seluruh emiten dan metadata dasar seperti listing board serta shares.',
                'collector' => 'src/SymbolUniverse.php',
                'notes' => 'Dipakai sebagai master symbol karena stabil dan ringan. Ini mirror GitHub, bukan endpoint IDX resmi.',
            ],
            [
                'name' => 'IDX Data Services',
                'status' => 'research',
                'requires_login' => false,
                'purpose' => 'Calon sumber resmi tambahan untuk free float, historical, corporate action, dan reference data.',
                'collector' => 'belum',
                'notes' => 'Perlu integrasi lebih lanjut karena akses data granular IDX tidak seluruhnya dibuka publik tanpa layanan data resmi.',
            ],
        ];
    }

    public static function targetDatasets(): array
    {
        return [
            [
                'dataset' => 'broker_snapshot_daily',
                'priority' => 'P0',
                'status' => 'ready',
                'fields' => [
                    'symbol',
                    'from_date',
                    'to_date',
                    'market_value',
                    'market_volume',
                    'brokers_buy',
                    'brokers_sell',
                    'updated_at',
                ],
                'why' => 'Fondasi utama untuk akumulasi, konsentrasi buyer, dominance gap, dan persistence broker.',
            ],
            [
                'dataset' => 'price_history',
                'priority' => 'P0',
                'status' => 'partial',
                'fields' => [
                    'symbol',
                    'timestamp',
                    'close',
                    'open',
                    'high',
                    'low',
                    'volume',
                    'interval',
                ],
                'why' => 'Dibutuhkan untuk mendeteksi base, breakout, relative strength, extension, dan volume anomaly.',
            ],
            [
                'dataset' => 'symbol_reference',
                'priority' => 'P0',
                'status' => 'ready',
                'fields' => [
                    'symbol',
                    'company_name',
                    'listing_board',
                    'listed_shares',
                ],
                'why' => 'Dipakai untuk master symbol full market dan normalisasi likuiditas.',
            ],
            [
                'dataset' => 'float_reference',
                'priority' => 'P1',
                'status' => 'research',
                'fields' => [
                    'symbol',
                    'free_float_pct',
                    'free_float_shares',
                ],
                'why' => 'Akan memperbaiki scoring tekanan akumulasi terhadap float, bukan hanya turnover.',
            ],
            [
                'dataset' => 'corporate_context',
                'priority' => 'P2',
                'status' => 'research',
                'fields' => [
                    'symbol',
                    'event_type',
                    'event_date',
                    'headline',
                ],
                'why' => 'Membantu memfilter sinyal palsu akibat rights issue, tender, atau aksi korporasi lain.',
            ],
        ];
    }

    public static function scoringLayers(): array
    {
        return [
            [
                'layer' => 'Broker Layer',
                'status' => 'implemented_partial',
                'features' => [
                    'buy_market_share',
                    'buy_lot_share',
                    'buy_concentration',
                    'dominance_gap',
                    'max_buy_freq',
                ],
            ],
            [
                'layer' => 'Price-Volume Layer',
                'status' => 'next',
                'features' => [
                    'base detection',
                    'breakout readiness',
                    'price extension',
                    'volume anomaly 20D',
                    'close vs avg buy price',
                ],
            ],
            [
                'layer' => 'Context Layer',
                'status' => 'next',
                'features' => [
                    'free float pressure',
                    'relative strength vs index',
                    'sector confirmation',
                    'corporate action filter',
                ],
            ],
        ];
    }
}
