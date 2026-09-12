import Chart from 'chart.js/auto';
import Dropzone from 'dropzone';
import Sortable from 'sortablejs';

// Expose as globals for inline scripts in Blade views
window.Chart = Chart;
window.Dropzone = Dropzone;
window.Sortable = Sortable;

// Dropzone CSS — imported here so Vite bundles it into the CSS output
import 'dropzone/dist/dropzone.css';
