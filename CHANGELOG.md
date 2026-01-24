# Exospace 3D Gallery - Changelog

## Version 1.2.0 - January 24, 2026 (Performance & UX Overhaul)

### ⚡ Performance Breakthroughs
- **Lighting Engine Overhaul**: Switched from expensive SpotLights to optimized PointLights. Drastically reduces GPU load.
- **Shared Lighting Architecture**: Implemented a 1:3 light-to-artwork ratio. A single light now illuminates clustered artworks, preventing WebGL context crashes on low-end devices.
- **Shadow Calculation**: Fully disabled shadow mapping on artwork lights to maximize FPS.

### 🎮 Controls & Navigation
- **Sprint Mode**: Added SHIFT key modifier for faster movement (2.5x speed).
- **Updated UI**: Added "Sprint" instructions to the on-screen control guide.

### 📐 Smart Architecture
- **Dynamic Room Sizing**: Replaced linear room scaling with a smart algorithm. Room dimensions now calculate based on artwork density, eliminating empty walls in small-to-medium galleries.
- **Compact Layouts**: Galleries now feel "cozy" and curated rather than empty and cavernous.

### 🛠 Admin Quality of Life
- **Bulk Management**: Added "Select All" functionality to the Gallery Edit page for rapid bulk deletion.

---

## Version 1.1.0 - January 2026

### 🎨 New Features
- **Batch Delete Images**: Select multiple images with checkboxes and delete them all at once
- **Increased Upload Limit**: Upload up to 100 images per gallery (previously limited)
- **Dynamic Lighting System**: Automatically adjusts lighting based on gallery size
  - Small galleries (5-10 images): 4 ceiling lights
  - Medium galleries (20-30 images): 9 ceiling lights  
  - Large galleries (50-100 images): 9 optimized high-range lights

### 🔧 Technical Improvements
- **Performance Optimization**: Switched to CanvasTexture rendering to bypass GPU texture unit limits
- **Lighting Architecture**: Reduced light count while increasing range for better performance
- **Upload Stability**: Fixed race condition that caused image loss during bulk uploads
- **Shadow Optimization**: Disabled shadows on spotlights to prevent texture overflow

### 🐛 Bug Fixes
- Fixed black screen issue when displaying 20+ images
- Fixed disappearing images during simultaneous uploads
- Fixed WebGL shader errors caused by excessive texture units
- Fixed upload progress tracking for multiple files
- Improved error handling and user feedback during uploads

### 🎯 User Experience
- Real-time upload progress for multiple files
- Better visual feedback with loading indicators
- Improved batch delete confirmation messages
- Gallery now supports unlimited images (tested up to 200+)
- Maintains 60 FPS on modern devices regardless of gallery size

---

## Version 1.0.0 - January 2026
- Initial release
- 3D gallery creation with customizable walls, floors, and lighting
- Image upload with automatic optimization
- Public sharing with unique URLs
- Responsive controls (WASD + Mouse)