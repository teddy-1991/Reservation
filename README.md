# Reservation System

A full-stack booking system built for indoor golf simulator businesses. Customers can reserve simulator rooms online, and administrators can manage all reservations and business settings through a dedicated dashboard.

**Live:** [sportechgolf.com/booking](https://sportechgolf.com/booking/public) · [virtualteeup.com/booking](https://virtualteeup.com/booking/public)

---

## Tech Stack

- **Backend:** PHP, MySQL
- **Frontend:** HTML, CSS, JavaScript
- **Services:** Twilio (phone verification), SMTP (email confirmation)

---

## Features

### Customer Side

- Real-time time-slot availability by date and room
- Room selection with business-rule restrictions (e.g. room-specific booking windows)
- Canadian phone number verification via OTP (verified numbers skip re-verification)
- Automated booking confirmation emails
- Mobile-responsive design

### Admin Side

- Password-protected login
- Reservation management — view, edit, delete, and add notes per booking
- Customer search with visit count, total usage hours, and profile editing
- Weekly booking overview dashboard
- Configurable business hours, pricing table, and menu image uploads
- Public notice editor for customer-facing announcements
- Drag-and-drop reservation management
