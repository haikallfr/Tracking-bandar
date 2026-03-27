# Radar Bandar Data Blueprint

Tujuan sistem ini bukan menebak harga dengan pasti, tetapi menaikkan probabilitas kandidat saham yang berpotensi naik dengan menggabungkan:

1. Broker flow
2. Price-volume action
3. Context / reference data

## Sumber Yang Sudah Siap

### 1. Stockbit Market Detector
- Status: siap
- Butuh login: ya
- Kegunaan:
  - broker summary 7D
  - turnover agregat
  - volume agregat
  - buyer / seller concentration
  - freq broker

### 2. Stockbit Company Price Feed
- Status: parsial
- Butuh login: ya
- Kegunaan:
  - historikal close / minutely prices
  - dasar momentum dan price action
- Catatan:
  - endpoint sudah terdeteksi
  - parameter historikal penuh masih perlu dipetakan lebih lanjut

### 3. IDX Symbol Universe Mirror
- Status: siap
- Butuh login: tidak
- Kegunaan:
  - daftar simbol seluruh market
  - nama perusahaan
  - listing board
  - listed shares

## Dataset Target

### broker_snapshot_daily
- symbol
- from_date
- to_date
- market_value
- market_volume
- brokers_buy
- brokers_sell
- updated_at

### price_history
- symbol
- timestamp
- interval
- open
- high
- low
- close
- volume

### symbol_reference
- symbol
- company_name
- listing_board
- listed_shares

### float_reference
- symbol
- free_float_pct
- free_float_shares

### corporate_context
- symbol
- event_type
- event_date
- headline

## Scoring Layer

### Layer 1: Broker Layer
- buy_market_share
- buy_lot_share
- buy_concentration
- dominance_gap
- max_buy_freq

### Layer 2: Price-Volume Layer
- base detection
- breakout readiness
- price extension
- volume anomaly 20D
- close vs avg buy price

### Layer 3: Context Layer
- free float pressure
- relative strength vs index
- sector confirmation
- corporate action filter

## Arah Implementasi

Urutan paling rasional:

1. Sempurnakan collector historikal harga dari Stockbit login yang sama
2. Simpan histori broker snapshot harian
3. Tambahkan feature engine untuk breakout / volume anomaly / extension
4. Tambahkan filter free float dan aksi korporasi
5. Gabungkan jadi scoring probabilitas yang lebih matang
