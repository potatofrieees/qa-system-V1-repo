# QA System v48 — Setup & Change Notes

## 🐛 Bug Fixes
- **announcements.php** — Fixed `Undefined array key "per_page"` + Division by zero error
- **proposals.php** — Fixed same `per_page` bug
- **schedule.php** — Completely rewritten (see below)

## ✨ New Features

### 1. Smart Scheduling (admin/schedule.php)
- **Calendar View** — Visual monthly calendar with color-coded dates:
  - 🟢 Green = Available slots
  - 🟡 Amber = Partially booked
  - 🔴 Red = Fully booked
  - ⬛ Striped = Blackout / Closed
- **Auto-Generate Slots** ⚡ — Select date range + days of week + time templates → auto-creates slots (skips blackouts and existing)
- **Blackout Dates** 🚫 — Mark specific dates as unavailable (holiday, event, maintenance). Automatically deactivates slots on that date. Students/users see the date as "Office Closed"
- **Day Panel** — Click any calendar date to see slot details and manage them
- **Tabs**: Calendar | Slots | Appointments | Reservations | Blackouts

### 2. Student Registration (register.php)
- Self-registration with Data Privacy Act consent
- Fields: Full Name, Position/Designation, Department/Office, College, Program, Email, Phone, Password
- Email OTP verification after registration
- Notifies QA staff of new registrations
- Login page now shows "New student? Create an account →" link

### 3. Student Portal (/student/)
- **Separate dashboard** from faculty/staff portal
- **Student-specific sidebar**: Dashboard, Book Appointment, My Appointments, My Proposals, Notifications, Profile
- **Route**: After login, students go to student/dashboard.php

### 4. Student Appointments (student/appointments.php)
- Interactive calendar showing available/booked/closed dates
- Color-coded: Green=available, Red=full, Blue=your booking, Striped=office closed
- Blackout dates appear as "Office Closed" — students cannot book those
- Earliest available slot shown with quick-jump button
- Slot panel on the right — click date → see time slots → one-click booking

### 5. Room Reservation Form (student/appointments.php → Reserve a Room tab)
Matches the exact Google Form design provided:
- **Data Privacy consent** (radio button "Yes" required)
- **A. Requestor's Information**: Full Name, Position/Designation, Department/Office, Contact, Email
- **B. Reservation Details**: Purpose, Room selection, Date of Use, Time to Start, Time to End, Estimated Participants, Equipment needed, Additional notes
- Pre-fills user's name/email/college from their profile
- Reservation history shown above the form

### 6. Appointments open to all users (not just students)
- Faculty/staff users (user/ portal) also see appointment calendar
- The `user/appointments.php` already supports all roles

## 📁 New Files
```
register.php                    ← Student self-registration
student/
  dashboard.php                 ← Student-specific dashboard
  sidebar.php                   ← Student navigation
  appointments.php              ← Calendar + room reservation form
  proposals.php                 ← Submit/track proposals
  my_appointments.php           ← Redirect stub
  notifications.php             ← (copied from user/)
  profile.php                   ← (copied from user/)
  head.php                      ← (copied from user/)
database/
  migrate_v48.sql               ← Run this migration
```

## 🗄️ Database Changes (run migrate_v48.sql)
```sql
-- New table
CREATE TABLE schedule_blackouts (...)

-- New column (if not exists)
ALTER TABLE users ADD COLUMN student_id VARCHAR(50);
ALTER TABLE room_reservations ADD COLUMN attendees TEXT;
ALTER TABLE room_reservations ADD COLUMN approved_by INT;
ALTER TABLE room_reservations ADD COLUMN approved_at DATETIME;

-- New role
INSERT INTO roles ... ('student', 'Student', ...)
```

## 🚀 Quick Start
1. Run `database/migrate_v48.sql` in phpMyAdmin
2. Replace all files with this v48 package
3. Test at: `login.php` (existing users) or `register.php` (new student)
