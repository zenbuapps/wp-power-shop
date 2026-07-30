/**
 * 商品圖片佔位圖
 *
 * 舊版一頁商店用 `@/assets/images/defaultImage.jpg`，新版不引入二進位資產，
 * 改為 inline SVG data URI：
 * - 零額外 HTTP request、不受 build 資產路徑影響
 * - 1:1 正方形（配合 `aspect-square`），中央淺灰相機 icon
 */

/** 灰色相機佔位圖 SVG（400x400，viewBox 等比縮放） */
const PLACEHOLDER_SVG = [
	'<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400" role="img" aria-label="">',
	'<rect width="400" height="400" fill="#f3f4f6"/>',
	'<rect x="170" y="132" width="60" height="22" rx="8" fill="#d1d5db"/>',
	'<rect x="120" y="150" width="160" height="110" rx="12" fill="#d1d5db"/>',
	'<circle cx="200" cy="205" r="34" fill="#f3f4f6"/>',
	'<circle cx="200" cy="205" r="18" fill="#d1d5db"/>',
	'</svg>',
].join('')

/** 可直接放進 `<img src>` 的 data URI */
export const defaultImage = `data:image/svg+xml,${encodeURIComponent(
	PLACEHOLDER_SVG
)}`

export default defaultImage
