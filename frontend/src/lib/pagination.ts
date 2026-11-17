/** Page numbers to show around the current page, with `null` as an ellipsis. */
export function pageWindow(current: number, last: number, radius = 1): Array<number | null> {
  if (last <= 7) return Array.from({ length: last }, (_, i) => i + 1)

  const pages: Array<number | null> = [1]
  const start = Math.max(2, current - radius)
  const end = Math.min(last - 1, current + radius)

  if (start > 2) pages.push(null)
  for (let page = start; page <= end; page++) pages.push(page)
  if (end < last - 1) pages.push(null)
  pages.push(last)

  return pages
}
