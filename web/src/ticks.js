// Price steps by price band, in halalas. Must match BandTick in the engine
// (engine/book/book.go): the step depends on the price of the order itself.
export function tickFor(price) {
  if (price < 2500) return 1
  if (price < 5000) return 2
  if (price < 10000) return 5
  if (price < 25000) return 10
  if (price < 50000) return 20
  return 50
}

/** The valid prices at or below and at or above p; equal when p is valid. */
export function nearestTicks(p) {
  const t = tickFor(p)
  const down = Math.floor(p / t) * t
  if (down === p) return [p, p]
  let up = down + t
  const r = up % tickFor(up)
  if (r) up += tickFor(up) - r
  return [down, up]
}
