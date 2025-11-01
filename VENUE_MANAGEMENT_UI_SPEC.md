# Venue Management - Airbnb-Style UI Specification

## Overview

This UI specification is **heavily inspired by Airbnb's design patterns** but adapted specifically for the **Looking For Games (LFG) platform**. While Airbnb helps travelers find accommodations, this venue management module helps **athletes find places to play sports**.

The design philosophy borrows Airbnb's intuitive layout, clean information hierarchy, and user-friendly flows, but reimagined for sports venue management:
- **Venues** replace listings
- **Facilities** replace rooms
- **Athletes/Players** replace guests
- **Operating hours** replace availability calendars
- **Amenities** remain familiar (parking, restrooms, etc.)
- **Sports-specific features** like court capacity and surface types

This specification provides a complete guide for venue managers to manage their venues, facilities, operating hours, amenities, and closure dates in an LFG platform.

**Note**: This UI specification is designed for the `/management/venues` module - a standalone, full-featured management interface with complete CRUD (Create, Read, Update, Delete) capabilities for all venue-related operations.

---

## 1. VENUE DASHBOARD (Main Hub)

### Layout
```
┌─────────────────────────────────────────────────────────┐
│  My Venues                                    [+ Create] │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  ┌──────────────────┐  ┌──────────────────┐          │
│  │   [Venue Photo]  │  │   [Venue Photo]  │          │
│  │                  │  │                  │          │
│  │  Venue Name 1    │  │  Venue Name 2    │          │
│  │  ★★★★★ (4.5)    │  │  ★★★★☆ (4.0)    │          │
│  │  📍 Location     │  │  📍 Location     │          │
│  │  ───────────────  │  │  ───────────────  │          │
│  │  5 Facilities    │  │  3 Facilities    │          │
│  │  12 Events       │  │  8 Events        │          │
│  │  [Manage] [View] │  │  [Manage] [View] │          │
│  └──────────────────┘  └──────────────────┘          │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### Features
- **Grid View**: Display all owned venues in cards
- **Quick Stats**: Photos, ratings, facility count, event count per venue
- **Actions**: Quick access to Manage/View for each venue
- **Empty State**: "Create your first venue" with onboarding flow

---

## 2. GLOBAL NAVIGATION (Icon-Based Minimalist Menu)

### Desktop Sidebar Navigation
```
┌────────────────────────────────────────┐
│  Venue Management                      │
├────────────────────────────────────────┤
│                                        │
│  🏠 My Venues (Dashboard)              │
│                                        │
│  ─────────────────────────────────────│
│                                        │
│  Venue Management                      │
│  ✏️  Edit Venue                        │
│  📷 Manage Photos                      │
│  🏢 Facilities (CRUD)                  │
│  ⏰ Operating Hours (CRUD)             │
│  ✨ Amenities (CRUD)                   │
│  🚫 Closure Dates (CRUD)               │
│                                        │
│  ─────────────────────────────────────│
│                                        │
│  Business Operations                   │
│  📊 Analytics (View)                   │
│  📅 Bookings (Manage)                  │
│  ⭐ Reviews (View)                     │
│  👥 Staff/Members (Manage)             │
│                                        │
│  ─────────────────────────────────────│
│                                        │
│  Tools                                 │
│  🔍 View Public Page                   │
│  ⚙️  Settings                          │
│                                        │
└────────────────────────────────────────┘
```

### Features
- **Minimalist Icon Design**: Clear icons with labels
- **Category Grouping**: Logical grouping of related functions
- **Owner/Manager Focus**: Only venue management functions (no athlete features)
- **CRUD Indicators**: Shows operation type (CRUD, View, Manage)
- **Active State**: Highlight current page/section
- **Responsive**: Collapsible on mobile, expanded on desktop
- **Quick Access**: All backend endpoints have corresponding navigation items

---

## 3. CREATE/EDIT VENUE PAGE

### Section 1: Basic Information
```
┌────────────────────────────────────────────────────────┐
│  Create New Venue                                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Venue Name*                                            │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Basketball Court Complex                        │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Description                                            │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Premium basketball facilities in the heart of   │  │
│  │ the city. Multiple courts, modern equipment...  │  │
│  │                                                 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Address*                                               │
│  ┌─────────────────────────────────────────────────┐  │
│  │ 123 Sports Street, Brgy. Sportsville, Manila   │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Location (Click to set on map)                        │
│  ┌─────────────────────────────────────────────────┐  │
│  │                    [Google Maps]                │  │
│  │              📍 Set Location                    │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Section 2: Contact Information
```
┌────────────────────────────────────────────────────────┐
│  How players can reach you                              │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Phone Number                                           │
│  ┌─────────────────────────────────────────────────┐  │
│  │ +63 912 345 6789                                │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Email                                                  │
│  ┌─────────────────────────────────────────────────┐  │
│  │ venue@example.com                               │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Facebook Page URL                                      │
│  ┌─────────────────────────────────────────────────┐  │
│  │ https://facebook.com/venue                     │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Instagram Handle                                       │
│  ┌─────────────────────────────────────────────────┐  │
│  │ https://instagram.com/venue                    │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Website                                                │
│  ┌─────────────────────────────────────────────────┐  │
│  │ https://venue.com                              │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Section 3: Photos
```
┌────────────────────────────────────────────────────────┐
│  Venue Photos (5/20)                      [+ Add Photos]│
├────────────────────────────────────────────────────────┤
│                                                         │
│  Drag to reorder • Click to preview • Set as cover     │
│                                                         │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐  │
│  │ [Photo] │  │ [Photo] │  │ [Photo] │  │ [Photo] │  │
│  │  ⭐      │  │         │  │         │  │         │  │
│  │ Cover   │  │         │  │         │  │         │  │
│  │         │  │         │  │         │  │         │  │
│  │ [Delete]│  │ [Delete]│  │ [Delete]│  │ [Delete]│  │
│  │ [Cover] │  │ [Cover] │  │ [Cover] │  │ [Cover] │  │
│  └─────────┘  └─────────┘  └─────────┘  └─────────┘  │
│                                                         │
│  ┌─────────┐                                           │
│  │ [Photo] │                                           │
│  │         │                                           │
│  │         │                                           │
│  │         │                                           │
│  │ [Delete]│                                           │
│  │ [Cover] │                                           │
│  └─────────┘                                           │
│                                                         │
│  💡 Tip: Add at least 3 high-quality photos           │
│  Maximum 20 photos allowed                             │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Photo Upload Modal
```
┌────────────────────────────────────────────────────────┐
│  Upload Photos                                    [✕]  │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ┌─────────────────────────────────────────────────┐  │
│  │                                                 │  │
│  │         Drag & Drop Photos Here                │  │
│  │              or                                 │  │
│  │         [Browse Files]                          │  │
│  │                                                 │  │
│  │  Supported: JPG, PNG, GIF (Max 4MB each)       │  │
│  │                                                 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Selected Files (3):                                   │
│  ✓ venue-photo-1.jpg (2.1 MB)                         │
│  ✓ venue-photo-2.jpg (1.8 MB)                         │
│  ✓ venue-photo-3.jpg (3.2 MB)                         │
│                                                         │
│  [Cancel]  [Upload Photos]                             │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Photo Management Features
- **Drag & Drop Upload**: Easy photo upload interface
- **Reorder Photos**: Drag to change photo order
- **Set Cover Photo**: Mark one photo as primary/cover
- **Delete Photos**: Remove photos with confirmation
- **Preview**: Click to view full-size image
- **Limit Indicator**: Shows current count vs maximum (20)
- **File Validation**: Checks file type and size

### Section 4: House Rules
```
┌────────────────────────────────────────────────────────┐
│  House Rules                                            │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ┌─────────────────────────────────────────────────┐  │
│  │ • Wear proper sports shoes                      │  │
│  │ • No smoking or alcohol                         │  │
│  │ • Respect other players                         │  │
│  │ • Clean up after use                            │  │
│  │ • Maximum 2 hours per booking                   │  │
│  │                                                  │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  [Save as Draft]  [Publish Venue]                      │
│                                                         │
└────────────────────────────────────────────────────────┘
```

---

## 4. MANAGE FACILITIES PAGE

### Facilities List
```
┌────────────────────────────────────────────────────────┐
│  Manage Facilities                                      │
│  Venue: Basketball Court Complex       [+ Add Facility]│
├────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🏀 Court 1                                       │ │
│  │ Professional Basketball Court                    │ │
│  │ • Capacity: 20 players                          │ │
│  │ • Covered: Yes                                  │ │
│  │ • Price: ₱500/hour                              │ │
│  │ • Photos: 5                                     │ │
│  │ [Edit] [Delete]                                 │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🏀 Court 2                                       │ │
│  │ Standard Basketball Court                       │ │
│  │ • Capacity: 16 players                          │ │
│  │ • Covered: Yes                                  │ │
│  │ • Price: ₱400/hour                              │ │
│  │ • Photos: 3                                     │ │
│  │ [Edit] [Delete]                                 │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Add/Edit Facility Form
```
┌────────────────────────────────────────────────────────┐
│  Add New Facility                                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Facility Name (e.g., "Court 1", "Field A")            │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Court 1                                         │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Type* (Custom - type anything)                        │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Professional Basketball Court                   │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Price per Hour* (₱)                                   │
│  ┌─────────────────────────────────────────────────┐  │
│  │ 500                                              │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  Capacity (Max number of players)                      │
│  ┌─────────────────────────────────────────────────┐  │
│  │ 20                                              │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  ☑ Covered (Has roof/shelter)                         │
│                                                         │
│  Facility Photos (3/10)                  [+ Add Photos]│
│  ┌─────────┐  ┌─────────┐  ┌─────────┐               │
│  │ [Photo] │  │ [Photo] │  │ [Photo] │               │
│  │         │  │         │  │         │               │
│  │ [Delete]│  │ [Delete]│  │ [Delete]│               │
│  └─────────┘  └─────────┘  └─────────┘               │
│                                                         │
│  💡 Maximum 10 photos per facility                     │
│                                                         │
│  [Cancel]  [Save Facility]                             │
│                                                         │
└────────────────────────────────────────────────────────┘
```

---

## 5. OPERATING HOURS MANAGEMENT

### Operating Hours Calendar
```
┌────────────────────────────────────────────────────────┐
│  Operating Hours                                        │
│  Venue: Basketball Court Complex                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Monday                                            │ │
│  │ ☑ Open                                           │ │
│  │    From: [06:00] ───────── To: [22:00]          │ │
│  │ [Save]                                            │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Tuesday                                           │ │
│  │ ☑ Open                                           │ │
│  │    From: [06:00] ───────── To: [22:00]          │ │
│  │ [Save]                                            │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Wednesday                                         │ │
│  │ ☑ Open                                           │ │
│  │    From: [06:00] ───────── To: [22:00]          │ │
│  │ [Save]                                            │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ ...                                               │ │
│  │                                                   │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ Sunday                                            │ │
│  │ ☐ Closed                                         │ │
│  │ [Save]                                            │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Alternative: Quick Set Actions
```
[Copy Monday to All Days]  [Set All Days Same Hours]  [Clear All]
```

---

## 6. AMENITIES MANAGEMENT

### Amenities List & Add Form
```
┌────────────────────────────────────────────────────────┐
│  Venue Amenities                                        │
│  Venue: Basketball Court Complex                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Add New Amenity                                        │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Amenity Name*        ☑ Available               │  │
│  │ Parking                                         │  │
│  └─────────────────────────────────────────────────┘  │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Description (optional)                           │  │
│  │ Free parking for 50 vehicles                    │  │
│  └─────────────────────────────────────────────────┘  │
│  [+ Add Amenity]                                       │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Current Amenities (7)                                 │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ ✅ Parking                                       │ │
│  │    Free parking for 50 vehicles                 │ │
│  │    [Edit] [Delete]                              │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ ✅ Restroom                                      │ │
│  │    Clean restrooms with shower                  │ │
│  │    [Edit] [Delete]                              │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ ✅ Water Station                                 │ │
│  │    [Edit] [Delete]                              │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ ⚠️  Equipment Rental                             │ │
│  │    Currently unavailable                        │ │
│  │    [Edit] [Delete]                              │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Suggest Popular Amenities
```
💡 Quick Add Popular Amenities:
[Parking] [Restroom] [Water Station] [Changing Rooms]
[Lockers] [WiFi] [Seating] [Scoreboard] [First Aid]
```

---

## 7. CLOSURE DATES MANAGEMENT

### Upcoming Closures Calendar
```
┌────────────────────────────────────────────────────────┐
│  Closure Dates                                          │
│  Venue: Basketball Court Complex       [+ Add Closure] │
├────────────────────────────────────────────────────────┤
│                                                         │
│  [Calendar View]  [List View] ← Toggle                 │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ December 2025                                    │ │
│  │                                                  │ │
│  │  Mon  Tue  Wed  Thu  Fri  Sat  Sun             │ │
│  │  1    2    3    4    5    6    7               │ │
│  │  ...                                            │ │
│  │  25 🚫  26    27    28    29    30            │ │
│  │  (Christmas)                                    │ │
│  │                                                  │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Upcoming Closures                                     │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🚫 December 25, 2025                              │ │
│  │    All Day                                        │ │
│  │    Reason: Christmas Day                          │ │
│  │    [Edit] [Delete]                               │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🚫 January 1, 2026                                │ │
│  │    All Day                                        │ │
│  │    Reason: New Year's Day                         │ │
│  │    [Edit] [Delete]                               │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🚫 January 15, 2026                               │ │
│  │    10:00 - 14:00                                  │ │
│  │    Reason: Maintenance                           │ │
│  │    [Edit] [Delete]                               │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Add Closure Date Form
```
┌────────────────────────────────────────────────────────┐
│  Add Closure Date                                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  Closure Date*                                         │
│  ┌─────────────────────────────────────────────────┐  │
│  │ 📅 Dec 25, 2025                                 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  ☑ All Day                                             │
│                                                         │
│  If not all day:                                       │
│  From: [10:00] ─────── To: [14:00]                    │
│                                                         │
│  Reason (Optional)                                     │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Christmas Day                                   │  │
│  └─────────────────────────────────────────────────┘  │
│                                                         │
│  [Cancel]  [Add Closure]                               │
│                                                         │
└────────────────────────────────────────────────────────┘
```

---

## 8. ANALYTICS DASHBOARD

### Overview Layout
```
┌────────────────────────────────────────────────────────┐
│  Analytics Dashboard                                   │
│  Venue: Basketball Court Complex                       │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Date Range Filter: [Last 7 Days ▼] [Apply]          │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Summary Statistics Cards
```
┌────────────────────────────────────────────────────────┐
│                                                        │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐│
│  │ 💰 Total      │ │ 📅 Total      │ │ 👥 Total      ││
│  │    Revenue    │ │    Events    │ │    Participants││
│  │              │ │              │ │              ││
│  │  ₱125,000    │ │     48       │ │    245       ││
│  │  +12% ⬆️     │ │   +5 this    │ │   +18 this   ││
│  │  vs last week│ │   week       │ │   week       ││
│  └──────────────┘ └──────────────┘ └──────────────┘│
│                                                        │
│  ┌──────────────┐                                    │
│  │ 📊 Average    │                                    │
│  │    Participants│                                    │
│  │              │                                    │
│  │    5.1       │                                    │
│  │   per event  │                                    │
│  └──────────────┘                                    │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Weekly Revenue Chart
```
┌────────────────────────────────────────────────────────┐
│  Weekly Revenue                                        │
├────────────────────────────────────────────────────────┤
│                                                        │
│      ₱                                ╱╲              │
│  30k │                               ╱  ╲             │
│      │                      ╱╲       ╱    ╲            │
│  20k │                     ╱  ╲    ╱       ╲           │
│      │          ╱╲       ╱    ╲ ╱╲          ╲          │
│  10k │  ╱╲      ╱  ╲   ╱      ╱  ╲           ╲         │
│      │ ╱  ╲  ╱╱      ╱╱      ╱    ╲          ╱         │
│   0k └────────────────────────────────────────────    │
│       Mon  Tue  Wed  Thu  Fri  Sat  Sun               │
│                                                        │
│  [Bar Chart] [Line Chart] [Export Data]               │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Recent Events Table
```
┌────────────────────────────────────────────────────────┐
│  Recent Events (Last 7 Days)                           │
├────────────────────────────────────────────────────────┤
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ Event Name        │ Date         │ Participants ││
│  ├──────────────────────────────────────────────────┤│
│  │ Basketball Match  │ Nov 2, 2025  │     12       ││
│  │ Morning Practice  │ Nov 1, 2025  │     8        ││
│  │ Tournament Final  │ Oct 31, 2025 │     16       ││
│  │ Evening Session   │ Oct 30, 2025 │     10       ││
│  │ ...               │ ...          │     ...      ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  [Show All Events] [Export to CSV]                    │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Venue Performance Breakdown
```
┌────────────────────────────────────────────────────────┐
│  Per-Venue Performance                                 │
├────────────────────────────────────────────────────────┤
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ Venue Name        │ Events │ Participants │ Earnings ││
│  ├──────────────────────────────────────────────────┤│
│  │ Court Complex A   │   25   │     125      │ ₱65,000  ││
│  │ Court Complex B   │   18   │      90      │ ₱48,000  ││
│  │ Sports Center     │    5   │      30      │ ₱12,000  ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  Sort by: [Revenue ▼] [Events] [Participants]        │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Features
- **Summary Cards**: Quick overview of key metrics with trend indicators
- **Weekly Chart**: Visual revenue trends (bar or line chart toggle)
- **Recent Events**: Quick view of latest activity
- **Venue Comparison**: Performance breakdown across all venues
- **Frontend Filtering**: Date range selection, sort options
- **Export Options**: Download data as CSV/PDF
- **Responsive Design**: Charts adapt to screen size
- **Empty States**: Friendly messages when no data available

### Mobile View
```
┌──────────────────────────┐
│ 📊 Analytics             │
├──────────────────────────┤
│                          │
│ 💰 Revenue               │
│    ₱125,000              │
│    +12% ⬆️               │
│                          │
│ 📅 Events                │
│    48                    │
│    +5 this week          │
│                          │
│ 👥 Participants          │
│    245                   │
│    +18 this week         │
│                          │
│ 📊 Average/Event         │
│    5.1                   │
│                          │
│ ─────────────────────    │
│                          │
│ Weekly Revenue Chart     │
│ [Swipe to View]          │
│                          │
│ [View All Details]       │
│                          │
└──────────────────────────┘
```

---

## 9. BOOKINGS MANAGEMENT

### Bookings Dashboard
```
┌────────────────────────────────────────────────────────┐
│  Bookings Management                                   │
│  [All Venues ▼]  [Pending ▼]  [Search...]             │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Status Filters:                                       │
│  [All] [Pending] [Approved] [Rejected] [Cancelled]   │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Booking List View
```
┌────────────────────────────────────────────────────────┐
│  Pending Bookings (5)                                  │
├────────────────────────────────────────────────────────┤
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ 🏀 Basketball Match                               ││
│  │ Court Complex A - Court 1                        ││
│  │                                                   ││
│  │ 📅 Nov 15, 2025  ⏰ 2:00 PM - 4:00 PM           ││
│  │ 👤 John Doe                                       ││
│  │ 💰 ₱1,000                                         ││
│  │                                                   ││
│  │ [Approve] [Reject] [View Details]                ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ ⚽ Soccer Training                                 ││
│  │ Sports Center - Field A                          ││
│  │                                                   ││
│  │ 📅 Nov 16, 2025  ⏰ 10:00 AM - 12:00 PM         ││
│  │ 👤 Jane Smith                                     ││
│  │ 💰 ₱800                                           ││
│  │                                                   ││
│  │ [Approve] [Reject] [View Details]                ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Booking Details Modal
```
┌────────────────────────────────────────────────────────┐
│  Booking Details                                  [✕]  │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Event: Basketball Match                              │
│  Venue: Court Complex A                               │
│  Facility: Court 1                                    │
│                                                        │
│  Date: November 15, 2025                              │
│  Time: 2:00 PM - 4:00 PM                             │
│  Duration: 2 hours                                    │
│                                                        │
│  Booked by: John Doe                                  │
│  Email: john@example.com                              │
│  Phone: +63 912 345 6789                             │
│                                                        │
│  Price: ₱1,000                                        │
│  Status: Pending                                      │
│                                                        │
│  Special Requests:                                    │
│  Need extra chairs for spectators                    │
│                                                        │
│  ─────────────────────────────────────────────────   │
│                                                        │
│  [Approve Booking]  [Reject Booking]  [Close]        │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Calendar View
```
┌────────────────────────────────────────────────────────┐
│  Bookings Calendar                    [November 2025 ▼]│
├────────────────────────────────────────────────────────┤
│                                                        │
│   Mon    Tue    Wed    Thu    Fri    Sat    Sun      │
│                              1      2      3      4    │
│    5      6      7      8      9     10     11        │
│   12     13     14    [15]    16     17     18        │
│                       ●●●                              │
│   19     20     21     22     23     24     25        │
│   26     27     28     29     30                      │
│                                                        │
│  ● = Booking exists                                   │
│                                                        │
│  Selected: November 15, 2025                          │
│  ┌──────────────────────────────────────────────────┐│
│  │ 2:00 PM - Basketball Match (Pending)             ││
│  │ 4:00 PM - Soccer Practice (Approved)             ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Approve/Reject Confirmation
```
┌────────────────────────────────────────────────────────┐
│  Approve Booking?                                      │
├────────────────────────────────────────────────────────┤
│                                                        │
│  You are about to approve this booking:               │
│                                                        │
│  Event: Basketball Match                              │
│  Date: November 15, 2025                              │
│  Time: 2:00 PM - 4:00 PM                             │
│  Customer: John Doe                                   │
│                                                        │
│  The customer will be notified via email.             │
│                                                        │
│  [Cancel]  [Confirm Approval]                         │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Reschedule Interface
```
┌────────────────────────────────────────────────────────┐
│  Reschedule Booking                               [✕]  │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Current Booking:                                     │
│  Date: November 15, 2025                              │
│  Time: 2:00 PM - 4:00 PM                             │
│                                                        │
│  New Date*                                            │
│  ┌─────────────────────────────────────────────────┐  │
│  │ 📅 Nov 16, 2025                                 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                        │
│  New Time*                                            │
│  From: [10:00 AM ▼]  To: [12:00 PM ▼]               │
│                                                        │
│  ⚠️  Availability Check:                              │
│  ✅ Time slot available                               │
│                                                        │
│  Reason for Reschedule (Optional)                     │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Maintenance scheduled for original time         │  │
│  └─────────────────────────────────────────────────┘  │
│                                                        │
│  [Cancel]  [Reschedule Booking]                       │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Features
- **Status Management**: Approve, reject, or cancel bookings
- **Calendar View**: Visual representation of all bookings
- **Filters**: Filter by status, venue, date range
- **Search**: Search by customer name, event name
- **Conflict Detection**: Automatic detection of scheduling conflicts
- **Notifications**: Automatic email notifications to customers
- **Bulk Actions**: Approve/reject multiple bookings at once
- **Export**: Export booking data to CSV

### Mobile View
```
┌──────────────────────────┐
│ 📅 Bookings              │
├──────────────────────────┤
│                          │
│ [Pending ▼] [Search...]  │
│                          │
│ ┌────────────────────┐  │
│ │ Basketball Match   │  │
│ │ Court 1            │  │
│ │ Nov 15, 2:00 PM   │  │
│ │ John Doe           │  │
│ │ ₱1,000             │  │
│ │                    │  │
│ │ [Approve] [Reject] │  │
│ └────────────────────┘  │
│                          │
│ [View Calendar]          │
│                          │
└──────────────────────────┘
```

---

## 10. REVIEWS MANAGEMENT

### Reviews Dashboard
```
┌────────────────────────────────────────────────────────┐
│  Reviews Management                                    │
│  [All Venues ▼]  [All Ratings ▼]  [Sort: Recent ▼]   │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Average Rating: ★★★★☆ 4.3 (124 reviews)             │
│                                                        │
│  Rating Distribution:                                 │
│  ★★★★★ ████████████████████ 65 (52%)                │
│  ★★★★☆ ██████████ 35 (28%)                          │
│  ★★★☆☆ ████ 15 (12%)                                │
│  ★★☆☆☆ ██ 7 (6%)                                    │
│  ★☆☆☆☆ █ 2 (2%)                                     │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Reviews List
```
┌────────────────────────────────────────────────────────┐
│  All Reviews (124)                                     │
├────────────────────────────────────────────────────────┤
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ ★★★★★                                             ││
│  │ John Doe                          Nov 10, 2025   ││
│  │ Court Complex A                                  ││
│  │                                                   ││
│  │ "Excellent facilities! The courts are well-      ││
│  │  maintained and the staff is very friendly.      ││
│  │  Will definitely come back."                     ││
│  │                                                   ││
│  │ 👍 Helpful (12)                                   ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ ★★★★☆                                             ││
│  │ Jane Smith                        Nov 8, 2025    ││
│  │ Sports Center                                    ││
│  │                                                   ││
│  │ "Great venue overall. Only issue was parking    ││
│  │  was a bit limited during peak hours."          ││
│  │                                                   ││
│  │ 👍 Helpful (8)                                    ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ ★★★☆☆                                             ││
│  │ Mike Johnson                      Nov 5, 2025    ││
│  │ Court Complex A                                  ││
│  │                                                   ││
│  │ "Decent place but could use better lighting     ││
│  │  in the evening."                                ││
│  │                                                   ││
│  │ 👍 Helpful (3)                                    ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  [Load More Reviews]                                  │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Filter & Sort Options
```
┌────────────────────────────────────────────────────────┐
│  Filters                                               │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Venue                                                │
│  ☑ All Venues                                         │
│  ☐ Court Complex A                                    │
│  ☐ Court Complex B                                    │
│  ☐ Sports Center                                      │
│                                                        │
│  Rating                                               │
│  ☑ All Ratings                                        │
│  ☐ 5 Stars                                            │
│  ☐ 4 Stars                                            │
│  ☐ 3 Stars                                            │
│  ☐ 2 Stars                                            │
│  ☐ 1 Star                                             │
│                                                        │
│  Date Range                                           │
│  From: [Nov 1, 2025 ▼]                               │
│  To: [Nov 30, 2025 ▼]                                │
│                                                        │
│  Sort By                                              │
│  ● Most Recent                                        │
│  ○ Highest Rating                                     │
│  ○ Lowest Rating                                      │
│  ○ Most Helpful                                       │
│                                                        │
│  [Apply Filters]  [Reset]                             │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Features
- **Read-Only**: Owners can view but not edit/delete reviews
- **Average Rating**: Display overall rating with distribution
- **Filters**: Filter by venue, rating, date range
- **Sort Options**: Sort by date, rating, helpfulness
- **Pagination**: Load more reviews as needed
- **Export**: Export reviews to CSV for analysis
- **Insights**: See trends in ratings over time

**Note**: Reviews are user-generated content. Venue owners cannot edit or delete reviews to maintain transparency and trust.

### Mobile View
```
┌──────────────────────────┐
│ ⭐ Reviews               │
├──────────────────────────┤
│                          │
│ Average: ★★★★☆ 4.3      │
│ 124 reviews              │
│                          │
│ [Filter ▼] [Sort ▼]     │
│                          │
│ ┌────────────────────┐  │
│ │ ★★★★★              │  │
│ │ John Doe           │  │
│ │ Nov 10, 2025       │  │
│ │                    │  │
│ │ "Excellent         │  │
│ │  facilities!..."   │  │
│ │                    │  │
│ │ 👍 12              │  │
│ └────────────────────┘  │
│                          │
│ [Load More]              │
│                          │
└──────────────────────────┘
```

---

## 11. STAFF & MEMBERS MANAGEMENT

### Staff Dashboard
```
┌────────────────────────────────────────────────────────┐
│  Staff & Members                                       │
│  Venue: Court Complex A                [+ Add Member] │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Total Staff: 5                                       │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Staff List
```
┌────────────────────────────────────────────────────────┐
│  Staff Members (5)                                     │
├────────────────────────────────────────────────────────┤
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ 👤 John Doe                                       ││
│  │ Owner (Primary)                                  ││
│  │ Added: Oct 1, 2025                               ││
│  │ Email: john@example.com                          ││
│  │                                                   ││
│  │ [Cannot Remove - Primary Owner]                  ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ 👤 Jane Smith                                     ││
│  │ Manager                                          ││
│  │ Added: Oct 15, 2025                              ││
│  │ Email: jane@example.com                          ││
│  │                                                   ││
│  │ [Remove Member]                                  ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
│  ┌──────────────────────────────────────────────────┐│
│  │ 👤 Mike Johnson                                   ││
│  │ Staff                                            ││
│  │ Added: Nov 1, 2025                               ││
│  │ Email: mike@example.com                          ││
│  │                                                   ││
│  │ [Remove Member]                                  ││
│  └──────────────────────────────────────────────────┘│
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Add Member Form
```
┌────────────────────────────────────────────────────────┐
│  Add Staff Member                                 [✕]  │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Search User*                                         │
│  ┌─────────────────────────────────────────────────┐  │
│  │ Search by username or email...              🔍 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                        │
│  Search Results:                                      │
│  ┌─────────────────────────────────────────────────┐  │
│  │ ● Sarah Wilson (sarah@example.com)              │  │
│  │ ○ Tom Brown (tom@example.com)                   │  │
│  │ ○ Lisa Davis (lisa@example.com)                 │  │
│  └─────────────────────────────────────────────────┘  │
│                                                        │
│  Role*                                                │
│  ● Manager                                            │
│  ○ Staff                                              │
│                                                        │
│  Permissions:                                         │
│  ☑ Manage bookings                                    │
│  ☑ View analytics                                     │
│  ☐ Edit venue details                                 │
│  ☐ Manage staff                                       │
│                                                        │
│  [Cancel]  [Add Member]                               │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Remove Member Confirmation
```
┌────────────────────────────────────────────────────────┐
│  Remove Staff Member?                                  │
├────────────────────────────────────────────────────────┤
│                                                        │
│  Are you sure you want to remove:                     │
│                                                        │
│  Name: Jane Smith                                     │
│  Role: Manager                                        │
│  Email: jane@example.com                              │
│                                                        │
│  This action cannot be undone. They will lose         │
│  access to manage this venue.                         │
│                                                        │
│  [Cancel]  [Remove Member]                            │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Empty State
```
┌────────────────────────────────────────────────────────┐
│                                                        │
│                    👥                                  │
│                                                        │
│            No Staff Members Yet                        │
│                                                        │
│     Add team members to help manage your venue        │
│                                                        │
│              [+ Add First Member]                      │
│                                                        │
└────────────────────────────────────────────────────────┘
```

### Features
- **Add Members**: Search and add users by username/email
- **Role Assignment**: Assign Manager or Staff roles
- **Permissions**: Set specific permissions per role
- **Remove Members**: Remove staff with confirmation
- **Primary Owner**: Cannot be removed (venue creator)
- **Activity Log**: Track who added/removed members

### Mobile View
```
┌──────────────────────────┐
│ 👥 Staff & Members       │
├──────────────────────────┤
│                          │
│ Total: 5 members         │
│                          │
│ [+ Add Member]           │
│                          │
│ ┌────────────────────┐  │
│ │ 👤 John Doe        │  │
│ │ Owner (Primary)    │  │
│ │ Oct 1, 2025        │  │
│ └────────────────────┘  │
│                          │
│ ┌────────────────────┐  │
│ │ 👤 Jane Smith      │  │
│ │ Manager            │  │
│ │ Oct 15, 2025       │  │
│ │ [Remove]           │  │
│ └────────────────────┘  │
│                          │
└──────────────────────────┘
```

---

## 12. VENUE VIEW (Public - For Athletes)

### Venue Details Page
```
┌────────────────────────────────────────────────────────┐
│  Basketball Court Complex                               │
│  ★★★★★ 4.5 (124 reviews)                              │
├────────────────────────────────────────────────────────┤
│                                                         │
│  [Gallery View - Swipeable Photos]                     │
│  ┌─────┐┌─────┐┌─────┐┌─────┐┌─────┐                 │
│  │[1/5]││[2/5]││[3/5]││[4/5]││[5/5]│                 │
│  └─────┘└─────┘└─────┘└─────┘└─────┘                 │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  📍 123 Sports Street, Brgy. Sportsville, Manila       │
│  📞 +63 912 345 6789                                   │
│  📧 venue@example.com                                  │
│  🌐 https://venue.com                                  │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ⏰ Operating Hours                                    │
│  • Mon-Fri: 6:00 AM - 10:00 PM                        │
│  • Sat: 7:00 AM - 9:00 PM                             │
│  • Sun: Closed                                         │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  🏢 Facilities (2)                                     │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🏀 Court 1 - Professional Basketball Court      │ │
│  │ • 20 players max                                 │ │
│  │ • Covered                                        │ │
│  │ • ₱500/hour                                      │ │
│  │ [View Details]                                   │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │ 🏀 Court 2 - Standard Basketball Court          │ │
│  │ • 16 players max                                 │ │
│  │ • Covered                                        │ │
│  │ • ₱400/hour                                      │ │
│  │ [View Details]                                   │ │
│  └──────────────────────────────────────────────────┘ │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ✨ Amenities                                          │
│  ✓ Parking ✓ Restroom ✓ Water Station                │
│  ✓ Changing Rooms ✓ WiFi ✓ Scoreboard                │
│  ✗ Equipment Rental                                   │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  📋 House Rules                                        │
│  • Wear proper sports shoes                           │
│  • No smoking or alcohol                              │
│  • Respect other players                              │
│  • Clean up after use                                 │
│  • Maximum 2 hours per booking                        │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ⚠️  Upcoming Closures                                │
│  🚫 Closed Dec 25, 2025 - Christmas Day               │
│  🚫 Closed Jan 1, 2026 - New Year's Day              │
│                                                         │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ⭐ Reviews (124)                                      │
│  [View All Reviews ▼]                                 │
│                                                         │
└────────────────────────────────────────────────────────┘
│                                                         │
│            [Create Event Here] [Contact Venue]         │
│                                                         │
└────────────────────────────────────────────────────────┘
```

---

## 13. QUICK ACTIONS & MOBILE VIEWS

### Mobile Navigation
```
┌──────────────────────────┐
│ Venue Management         │
│                          │
│ 🏠 My Venues            │
│                          │
│ ────────────────────────│
│                          │
│ ✏️  Edit Venue          │
│ 📷 Manage Photos        │
│ 🏢 Facilities           │
│ ⏰ Operating Hours      │
│ ✨ Amenities            │
│ 🚫 Closure Dates        │
│                          │
│ ────────────────────────│
│                          │
│ 📊 Analytics            │
│ 📅 Bookings             │
│ ⭐ Reviews              │
│ 👥 Staff/Members        │
│                          │
│ ────────────────────────│
│                          │
│ 🔍 View Public Page     │
│ ⚙️  Settings            │
│                          │
└──────────────────────────┘
```

**Note**: Navigation shows only venue owner/manager functions. No athlete/player features included.

### Empty States
```
┌────────────────────────────────────────┐
│                                        │
│        📸                               │
│                                        │
│   No Photos Yet                        │
│   Add at least 3 photos to             │
│   showcase your venue                  │
│                                        │
│     [+ Add Photos]                     │
│                                        │
└────────────────────────────────────────┘

┌────────────────────────────────────────┐
│                                        │
│        ⏰                               │
│                                        │
│   No Operating Hours Set               │
│   Let players know when                │
│   you're open                          │
│                                        │
│     [Set Hours]                        │
│                                        │
└────────────────────────────────────────┘
```

---

## 14. API INTEGRATION POINTS

### Venue Management
- `POST /api/venues/create` - Create venue with all fields
- `POST /api/venues/edit/{id}` - Update venue
- `DELETE /api/venues/delete/{id}` - Delete venue
- `GET /api/venues/owner` - Get all venues for authenticated owner

### Facilities
- `POST /api/venues/{venueId}/facilities` - Create facility
- `POST /api/venues/{venueId}/facilities/edit/{id}` - Update facility
- `DELETE /api/venues/{venueId}/facilities/delete/{id}` - Delete facility

### Operating Hours
- `GET /api/venues/{venueId}/operating-hours` - Get all hours
- `POST /api/venues/{venueId}/operating-hours` - Add/update hours
- `PUT /api/venues/{venueId}/operating-hours/{id}` - Update specific day
- `DELETE /api/venues/{venueId}/operating-hours/{id}` - Delete hours

### Amenities
- `GET /api/venues/{venueId}/amenities` - Get all amenities
- `POST /api/venues/{venueId}/amenities` - Add amenity
- `PUT /api/venues/{venueId}/amenities/{id}` - Update amenity
- `DELETE /api/venues/{venueId}/amenities/{id}` - Delete amenity

### Closure Dates
- `GET /api/venues/{venueId}/closure-dates` - Get all closures
- `POST /api/venues/{venueId}/closure-dates` - Add closure
- `PUT /api/venues/{venueId}/closure-dates/{id}` - Update closure
- `DELETE /api/venues/{venueId}/closure-dates/{id}` - Delete closure

### Analytics
- `GET /api/venues/analytics/{venueId?}` - Get venue analytics (all venues if venueId omitted)

### Bookings
- `GET /api/venues/bookings` - List all bookings for owner's venues
- `PUT /api/venues/bookings/{id}/status` - Update booking status (approve/reject)
- `POST /api/venues/bookings/{id}/cancel` - Cancel a booking
- `PATCH /api/venues/bookings/{id}/reschedule` - Reschedule a booking

### Reviews
- `GET /api/venues/{venueId}/reviews` - Get all reviews for a venue (paginated)

### Staff/Members
- `GET /api/venues/{venueId}/members` - List all staff members
- `POST /api/venues/{venueId}/addmembers` - Add a new staff member

### Photos
- `POST /api/venues/{venueId}/facilities/{facilityId}/photos` - Add facility photos
- `DELETE /api/venues/{venueId}/facilities/{facilityId}/photos/{photoId}` - Delete facility photo

---

## 15. USER FLOWS

### Flow 1: Create New Venue
1. User clicks "Create Venue"
2. Fill basic info (name, description, address)
3. Set location on Google Maps
4. Add contact information
5. Upload at least 3 photos
6. Add house rules
7. Save draft or publish

### Flow 2: Setup Facility
1. Navigate to "Manage Facilities"
2. Click "Add Facility"
3. Enter facility name
4. Type custom facility type
5. Set price per hour
6. Set capacity (optional)
7. Check "Covered" if applicable
8. Upload facility photos
9. Save

### Flow 3: Configure Operating Hours
1. Navigate to "Operating Hours"
2. Select day of week
3. Toggle "Closed" or set open/close times
4. Click "Save" for each day
5. Or use "Copy to All Days"

### Flow 4: Add Amenities
1. Navigate to "Amenities"
2. Type amenity name
3. Add optional description
4. Toggle availability
5. Click "Add Amenity"
6. Repeat for all amenities

### Flow 5: Set Closure Dates
1. Navigate to "Closure Dates"
2. Click "Add Closure"
3. Select date from calendar
4. Choose "All Day" or set specific hours
5. Add optional reason
6. Save

### Flow 6: View Analytics
1. Navigate to "Analytics" from sidebar
2. Review summary cards (revenue, events, participants)
3. Analyze weekly revenue chart
4. Check recent events table
5. Compare venue performance
6. Export data if needed

### Flow 7: Manage Bookings
1. Navigate to "Bookings" from sidebar
2. View pending booking requests
3. Click "View Details" on a booking
4. Review booking information and customer details
5. Click "Approve" or "Reject"
6. Confirm action in dialog
7. Customer receives automatic notification

### Flow 8: View Reviews
1. Navigate to "Reviews" from sidebar
2. View average rating and distribution
3. Apply filters (venue, rating, date range)
4. Sort reviews (recent, highest, lowest)
5. Read individual reviews
6. Export reviews for analysis

### Flow 9: Manage Staff
1. Navigate to "Staff/Members" from sidebar
2. View current staff list
3. Click "+ Add Member"
4. Search for user by username/email
5. Select user from results
6. Assign role (Manager/Staff)
7. Set permissions
8. Click "Add Member"
9. Confirmation message displayed

### Flow 10: Manage Photos
1. Navigate to venue or facility edit page
2. Go to Photos section
3. Click "+ Add Photos"
4. Drag & drop or browse files
5. Select multiple photos
6. Click "Upload Photos"
7. Drag photos to reorder
8. Click "Set as Cover" for primary photo
9. Click "Delete" to remove unwanted photos

---

## 16. RESPONSIVE DESIGN

### Desktop (> 1024px)
- 3-column grid for venue cards
- Side-by-side forms
- Expanded calendar view
- Larger photo galleries

### Tablet (768px - 1024px)
- 2-column grid for venue cards
- Stacked forms
- Full-width calendar
- Swipeable photo galleries

### Mobile (< 768px)
- Single column layout
- Bottom navigation bar
- Full-screen modals
- Touch-optimized controls
- Swipe gestures enabled

---

## 17. VALIDATION & ERROR HANDLING

### Field Validations
- **Required Fields**: Name*, Address*, Type*
- **URLs**: Facebook, Instagram, Website must be valid URLs
- **Email**: Must be valid email format
- **Phone**: Support international format
- **Hours**: Start time < End time

### Error Messages
```
❌ Error saving venue
Please fix the following errors:
• Venue name is required
• Address must be at least 10 characters
• Invalid email format

[OK]
```

### Success Messages
```
✅ Venue saved successfully!
Your venue is now visible to athletes.

[View Venue] [Back to Dashboard]
```

---

## 18. ADDITIONAL FEATURES

### Bulk Actions
- Select multiple items
- Bulk edit (e.g., mark all amenities unavailable)
- Bulk delete (with confirmation)

### Search & Filter
- Search venues by name
- Filter by facility type
- Filter by availability

### Export/Import
- Export venue data to PDF
- Export to CSV for backup

### Notifications
- New review received
- Booking request pending
- Closure dates approaching

---

## 19. API REFERENCE

### Base URL
```
http://your-domain.com/api
```

### Authentication
All venue management endpoints require JWT authentication:
```
Authorization: Bearer {token}
```

### Complete Endpoint List

#### Venues
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues` | List all venues (public) |
| GET | `/venues/owner` | Get venues owned by authenticated user |
| GET | `/venues/show/{id}` | Get single venue with all details |
| POST | `/venues/create` | Create new venue |
| POST | `/venues/edit/{id}` | Update venue |
| DELETE | `/venues/delete/{id}` | Delete venue |
| GET | `/venues/search` | Search venues by name |
| GET | `/venues/analytics/{id?}` | Get venue analytics |

#### Facilities
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/venues/{venueId}/facilities` | Create facility |
| GET | `/venues/{venueId}/facilities/{facilityId}` | Get single facility |
| POST | `/venues/{venueId}/facilities/edit/{facilityId}` | Update facility |
| DELETE | `/venues/{venueId}/facilities/delete/{facilityId}` | Delete facility |
| POST | `/venues/{venueId}/facilities/{facilityId}/photos` | Add facility photos |
| DELETE | `/venues/{venueId}/facilities/{facilityId}/photos/{photoId}` | Delete facility photo |

#### Operating Hours
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/{venueId}/operating-hours` | Get all operating hours |
| POST | `/venues/{venueId}/operating-hours` | Add/update operating hours |
| PUT | `/venues/{venueId}/operating-hours/{id}` | Update specific hours |
| DELETE | `/venues/{venueId}/operating-hours/{id}` | Delete operating hours |

#### Amenities
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/{venueId}/amenities` | Get all amenities |
| POST | `/venues/{venueId}/amenities` | Add amenity |
| PUT | `/venues/{venueId}/amenities/{id}` | Update amenity |
| DELETE | `/venues/{venueId}/amenities/{id}` | Delete amenity |

#### Closure Dates
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/{venueId}/closure-dates` | Get all closures |
| POST | `/venues/{venueId}/closure-dates` | Add closure date |
| PUT | `/venues/{venueId}/closure-dates/{id}` | Update closure |
| DELETE | `/venues/{venueId}/closure-dates/{id}` | Delete closure |

#### Bookings
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/bookings` | List all bookings for owner's venues |
| PUT | `/venues/bookings/{id}/status` | Update booking status |
| POST | `/venues/bookings/{id}/cancel` | Cancel a booking |
| PATCH | `/venues/bookings/{id}/reschedule` | Reschedule a booking |

#### Reviews
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/{venueId}/reviews` | Get all reviews (paginated) |

#### Staff/Members
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/venues/{venueId}/members` | List all staff members |
| POST | `/venues/{venueId}/addmembers` | Add a new staff member |

#### Photos
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/venues/{venueId}/facilities/{facilityId}/photos` | Add facility photos |
| DELETE | `/venues/{venueId}/facilities/{facilityId}/photos/{photoId}` | Delete facility photo |

### Response Formats

#### Single Venue (GET /venues/show/{id})
```json
{
  "status": "success",
  "data": {
    "venue": {
      "id": 12,
      "name": "Test Venue",
      "description": "Description here",
      "address": "123 Street",
      "latitude": 14.5995,
      "longitude": 120.9842,
      "phone_number": "+63 912 345 6789",
      "email": "venue@example.com",
      "facebook_url": "https://facebook.com/venue",
      "instagram_url": "https://instagram.com/venue",
      "website": "https://venue.com",
      "house_rules": "Rules here",
      "photos": [...],
      "facilities": [{
        "id": 1,
        "name": "Court 1",
        "type": "Professional Basketball Court",
        "price_per_hr": 500,
        "capacity": 20,
        "covered": true
      }],
      "operating_hours": [...],
      "amenities": [...],
      "closure_dates": [...]
    }
  }
}
```

#### Operating Hours Format
```json
{
  "id": 1,
  "venue_id": 12,
  "day_of_week": 0,
  "open_time": "06:00:00",
  "close_time": "22:00:00",
  "is_closed": false
}
```

#### Amenity Format
```json
{
  "id": 1,
  "venue_id": 12,
  "name": "Parking",
  "available": true,
  "description": "Free parking available"
}
```

#### Closure Date Format
```json
{
  "id": 1,
  "venue_id": 12,
  "closure_date": "2025-12-25",
  "reason": "Christmas Day",
  "all_day": true,
  "start_time": null,
  "end_time": null
}
```

#### Analytics Format (GET /venues/analytics/{venueId?})
```json
{
  "status": "success",
  "analytics": {
    "summary": {
      "revenue": 125000.00,
      "events": 48,
      "participants": 245,
      "average_participants": 5.1
    },
    "weekly_revenue": [
      {"day": "Mon", "revenue": 15000.00},
      {"day": "Tue", "revenue": 12000.00},
      {"day": "Wed", "revenue": 18000.00},
      {"day": "Thu", "revenue": 20000.00},
      {"day": "Fri", "revenue": 25000.00},
      {"day": "Sat", "revenue": 18000.00},
      {"day": "Sun", "revenue": 17000.00}
    ],
    "recent_events": [
      {
        "id": 123,
        "venue_id": 12,
        "name": "Basketball Match",
        "date": "2025-11-02"
      }
    ],
    "venue_performance": [
      {
        "venue_id": 12,
        "venue_name": "Court Complex A",
        "address": "123 Sports Street",
        "events": 25,
        "participants": 125,
        "earnings": 65000.00
      }
    ]
  }
}
```

**Notes:**
- `venueId` parameter is optional. If omitted, returns analytics for all venues owned/managed by authenticated user
- Revenue calculations automatically detect available pricing columns in the schema
- All values exclude cancelled events
- Weekly revenue based on current week (Mon-Sun)

#### Bookings Format (GET /venues/bookings)
```json
{
  "status": "success",
  "bookings": [
    {
      "id": 45,
      "event_id": 123,
      "event_name": "Basketball Match",
      "venue_id": 12,
      "venue_name": "Court Complex A",
      "facility_id": 5,
      "facility_name": "Court 1",
      "date": "2025-11-15",
      "start_time": "14:00:00",
      "end_time": "16:00:00",
      "customer_name": "John Doe",
      "customer_email": "john@example.com",
      "customer_phone": "+63 912 345 6789",
      "price": 1000.00,
      "status": "pending",
      "special_requests": "Need extra chairs",
      "created_at": "2025-11-10T10:30:00Z"
    }
  ]
}
```

#### Reviews Format (GET /venues/{venueId}/reviews)
```json
{
  "status": "success",
  "venue_id": 12,
  "average_rating": 4.3,
  "total_reviews": 124,
  "reviews": [
    {
      "id": 1,
      "user_id": 45,
      "username": "john_doe",
      "email": "john@example.com",
      "rating": 5,
      "comment": "Excellent facilities!",
      "reviewed_at": "2025-11-10T15:30:00Z",
      "created_at": "2025-11-10T15:30:00Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 7,
    "per_page": 20,
    "total": 124
  }
}
```

#### Staff Members Format (GET /venues/{venueId}/members)
```json
{
  "status": "success",
  "members": [
    {
      "id": 1,
      "user_id": 10,
      "username": "john_doe",
      "email": "john@example.com",
      "role": "owner",
      "is_primary_owner": true,
      "added_at": "2025-10-01T10:00:00Z"
    },
    {
      "id": 2,
      "user_id": 15,
      "username": "jane_smith",
      "email": "jane@example.com",
      "role": "manager",
      "is_primary_owner": false,
      "added_at": "2025-10-15T14:20:00Z"
    }
  ]
}
```

### Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Server Error

---

## END NOTES

### Design Inspiration

This UI specification is heavily inspired by **Airbnb's proven design patterns** and user experience flows, adapted for the LFG (Looking For Games) platform. Key design principles borrowed from Airbnb include:

- **Visual-first approach**: High-quality photos prominently featured
- **Information hierarchy**: Critical details (location, capacity) displayed first
- **Trust indicators**: Verification badges, ratings, reviews
- **Progressive disclosure**: Details revealed as user scrolls/deepens interest
- **Mobile-first responsive design**: Works flawlessly on all screen sizes
- **Intuitive navigation**: Clear CTAs and logical flow between sections

### Key Differences from Airbnb

| Airbnb Focus | LFG Venue Management Focus |
|--------------|---------------------------|
| Accommodation listings | Sports venue listings |
| Check-in/Check-out dates | Operating hours & availability |
| Guest capacity | Player capacity per facility |
| Property features | Court/field specifications |
| Booking reservations | Event-based bookings |
| Host responses | Venue owner contact info |

**Status**: ✅ All endpoints tested and verified  
**Last Updated**: January 11, 2025


