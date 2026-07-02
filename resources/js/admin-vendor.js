// resources/js/admin-vendor.js
//
// (Task H19 / audit H51) — bundles Chart.js, Dropzone, and SortableJS
// via npm/Vite instead of loading them from CDNs (cdnjs, unpkg,
// jsdelivr). The previous CDN approach had no SRI, no version pinning
// in package.json, and three different CDN providers.
//
// These libraries are exposed as globals (window.Chart, window.Dropzone,
// window.Sortable) because the admin views use them in inline <script>
// blocks that expect global access. A future refactor should extract
// those inline scripts into proper ES modules.

import Chart from 'chart.js/auto';
import Dropzone from 'dropzone';
import Sortable from 'sortablejs';

// Expose as globals for inline scripts in Blade views
window.Chart = Chart;
window.Dropzone = Dropzone;
window.Sortable = Sortable;

// Dropzone CSS — imported here so Vite bundles it into the CSS output
import 'dropzone/dist/dropzone.css';
