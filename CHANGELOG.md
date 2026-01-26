# Exospace 3D Gallery - Changelog

## Version 1.2.0 - January 24, 2026 (Performance & UX Overhaul)

### ⚡ Performance Breakthroughs
- **Proximity-Based Lighting Engine**: Replaced static lighting with an advanced proximity system. Lights now smoothly fade in/out based on player position, reducing active light count by 96%.
- **Massive FPS Boost**: Galleries with 100+ images now run smoothly on low-end hardware (laptops, integrated graphics).
- **Optimization Strategy**: Implemented "Render-on-Demand" logic for lighting calculations.

### 🎮 Enhanced Navigation
- **Variable Speed Control**: Added speed multipliers (1x, 2x, 4x, 8x) accessible via number keys [1-4].
- **Improved Sprint**: Refined SHIFT-sprint mechanics for smoother acceleration.
- **Smart Collision**: Implemented robust boundary detection to keep high-speed players safely inside the gallery walls.

### 📂 Media & Storage
- **Increased Upload Limits**: Bumped maximum file size to **10MB per image** (previously 5MB) to support high-res artwork.
- **Robust Error Handling**: Added detailed, user-friendly error messages for failed uploads (size limits, file types).
- **Upload Stability**: Fixed filesystem race conditions to ensure images appear immediately in the grid after upload.

### 📐 Visual & Architecture Updates
- **Dynamic Fill Lighting**: Solved "dark room" issues in large galleries by distributing fill lights evenly along the gallery length.
- **Atmospheric Ceilings**: Ceiling color now dynamically adapts to the selected lighting preset (Bright/Moody/Dramatic).
- **Shadow Optimization**: Strategic disabling of shadow casting on secondary lights to prioritize texture resolution and frame rate.

### 🛠 System Improvements
- **Platform Agnostic**: Generalized installer logic to support CodeCanyon, TemplateMonster, and Codester out of the box.
- **License System**: Updated installer to accept generic license keys for multi-marketplace support.

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