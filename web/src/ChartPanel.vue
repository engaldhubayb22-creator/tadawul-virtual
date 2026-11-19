<script setup>
// Candlestick chart for the selected symbol. History comes from the API;
// after that each live trade updates the last candle in place.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { CandlestickSeries, HistogramSeries, createChart } from 'lightweight-charts'
import { api, isoTime, onTrade, state } from './store'

const emit = defineEmits(['pick'])
const el = ref(null)
let chart, candles, volume, last = null, stop = null, resize = null

// The chart draws times as UTC; shift so the axis reads in Riyadh time.
const RIYADH = 3 * 3600
const minute = (ms) => Math.floor(ms / 60000) * 60 + RIYADH

async function load(symbol) {
  last = null
  candles.setData([])
  volume.setData([])
  if (!symbol) return
  const data = await api('GET', `/market/${symbol}/candles?limit=600`)
  if (symbol !== state.selected) return
  const rows = data.candles.map((c) => ({
    time: minute(Date.parse(isoTime(c.bucket))),
    open: c.open / 100, high: c.high / 100, low: c.low / 100, close: c.close / 100, vol: Number(c.volume),
  }))
  candles.setData(rows)
  volume.setData(rows.map(volBar))
  last = rows.at(-1) || null
  chart.timeScale().fitContent()
}

const volBar = (c) => ({ time: c.time, value: c.vol, color: c.close >= c.open ? '#16c78455' : '#ea394355' })

function applyTrade(t) {
  const time = minute(t.at)
  const price = t.price / 100
  if (!last || time > last.time) {
    last = { time, open: price, high: price, low: price, close: price, vol: t.qty }
  } else {
    last.high = Math.max(last.high, price)
    last.low = Math.min(last.low, price)
    last.close = price
    last.vol += t.qty
  }
  candles.update(last)
  volume.update(volBar(last))
}

onMounted(() => {
  chart = createChart(el.value, {
    autoSize: true,
    layout: { background: { color: 'transparent' }, textColor: '#7d8a99', fontFamily: 'inherit' },
    grid: { vertLines: { color: '#1a222d' }, horzLines: { color: '#1a222d' } },
    rightPriceScale: { borderColor: '#1e2733' },
    timeScale: { borderColor: '#1e2733', timeVisible: true, secondsVisible: false },
    crosshair: { mode: 0 },
    localization: { locale: 'en-US' },
  })
  candles = chart.addSeries(CandlestickSeries, {
    upColor: '#16c784', downColor: '#ea3943', wickUpColor: '#16c784', wickDownColor: '#ea3943', borderVisible: false,
  })
  volume = chart.addSeries(HistogramSeries, { priceFormat: { type: 'volume' }, priceScaleId: 'vol' })
  chart.priceScale('vol').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } })

  // A click on the chart sends that price to the order ticket.
  chart.subscribeClick((p) => {
    if (!p.point) return
    const price = candles.coordinateToPrice(p.point.y)
    if (price != null && price > 0) emit('pick', Math.round(price * 100))
  })
  stop = onTrade(applyTrade)
  load(state.selected).catch(() => {})
})

watch(() => state.selected, (s) => load(s).catch(() => {}))
onBeforeUnmount(() => { stop?.(); chart?.remove() })
</script>

<template>
  <div ref="el" class="chart"></div>
</template>
