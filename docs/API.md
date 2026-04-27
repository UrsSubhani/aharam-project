# Aharam API Reference

**Base URL:** `http://localhost/aharam/backend-api`  
**Production:** `https://api.yourdomain.com`

All responses follow the envelope:
```json
{ "success": true, "message": "...", "data": {}, "meta": {} }
```

Authentication uses a **Bearer token** in the `Authorization` header:
```
Authorization: Bearer <jwt_token>
```

---

## Authentication

### POST /login
Sign in and receive a JWT token.

**Body:**
```json
{ "email": "user@example.com", "password": "secret123" }
```
**Response:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ...",
    "user": { "id": 1, "name": "John", "email": "john@example.com", "role": "customer" }
  }
}
```

---

### POST /register
Create a new account.

**Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "secret123",
  "phone": "9876543210",
  "role": "customer"
}
```
Roles: `customer` | `restaurant_owner` | `delivery_partner`

---

### GET /me  `[Auth]`
Returns the authenticated user's profile. For `restaurant_owner` includes `restaurant` object; for `delivery_partner` includes `delivery_partner` object.

---

### POST /logout  `[Auth]`
Invalidates the current token (client should also clear localStorage).

---

### POST /forgot-password
**Body:** `{ "email": "user@example.com" }`  
Returns: OTP sent (mock — logged to server log in dev).

### POST /reset-password
**Body:** `{ "email": "...", "otp": "123456", "new_password": "newpass" }`

### POST /verify-otp
**Body:** `{ "email": "...", "otp": "123456", "type": "registration" }`

---

## Restaurants

### GET /restaurants
List nearby/all restaurants.

**Query params:**
| Param | Type | Description |
|-------|------|-------------|
| lat | float | User latitude |
| lng | float | User longitude |
| radius | int | Km radius (default 10) |
| search | string | Search by name |
| cuisine | string | Filter by cuisine |
| sort | string | `rating` \| `distance` \| `delivery_time` |
| page | int | Page number |

**Response data:** array of restaurant objects with `distance_km`, `is_open`, `has_subscription`.

---

### GET /restaurants/:id
Full restaurant details with menu preview.

---

### POST /restaurants  `[Auth: restaurant_owner]`
Create restaurant (tied to authenticated owner).

**Body:**
```json
{
  "name": "Biryani House",
  "description": "Authentic Hyderabadi biryani",
  "address": "123 Main St, Chennai",
  "city": "Chennai",
  "phone": "9876543210",
  "cuisine_type": "Biryani, North Indian",
  "opening_time": "10:00",
  "closing_time": "22:00",
  "lat": 13.0827,
  "lng": 80.2707
}
```

---

### PATCH /restaurants/:id  `[Auth: restaurant_owner]`
Update own restaurant details.

### PATCH /restaurants/:id/status  `[Auth: restaurant_owner]`
**Body:** `{ "is_active": true }`

### GET /restaurants/:id/stats  `[Auth: restaurant_owner]`
Today's orders, revenue, ratings summary.

---

## Menu

### GET /restaurants/:id/menu
Full menu grouped by category.

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1, "name": "Starters",
      "items": [
        { "id": 10, "name": "Chicken 65", "price": 180, "is_veg": false, "is_available": true }
      ]
    }
  ]
}
```

### GET /menu/:id
Single menu item details.

### POST /menu  `[Auth: restaurant_owner]`
**Body:**
```json
{
  "category_id": 1,
  "name": "Chicken Biryani",
  "description": "Fragrant basmati rice",
  "price": 220,
  "is_veg": false,
  "preparation_time": 25,
  "image_url": "https://..."
}
```

### PATCH /menu/:id  `[Auth: restaurant_owner]`
Update item (any fields).

### DELETE /menu/:id  `[Auth: restaurant_owner]`

### PATCH /menu/:id/availability  `[Auth: restaurant_owner]`
**Body:** `{ "is_available": false }`

### GET /menu/:id/categories  
Categories for a restaurant.

### POST /menu/categories  `[Auth: restaurant_owner]`
**Body:** `{ "restaurant_id": 1, "name": "Desserts", "sort_order": 5 }`

---

## Cart

All cart operations are server-side (stored in `cart_sessions` table).

### GET /cart  `[Auth]`
Returns current cart with `items`, `subtotal`, `restaurant`.

### POST /cart/add  `[Auth]`
**Body:** `{ "menu_item_id": 10, "quantity": 2 }`  
Returns error if items from a different restaurant already in cart.

### PATCH /cart/item/:id  `[Auth]`
**Body:** `{ "quantity": 3 }` — set to 0 to remove.

### DELETE /cart/item/:id  `[Auth]`

### DELETE /cart  `[Auth]`
Clear entire cart.

---

## Orders

### POST /orders  `[Auth: customer]`
Place an order.

**Body:**
```json
{
  "address_id": 1,
  "payment_method": "online",
  "coupon_code": "SAVE50",
  "special_instructions": "No onions please"
}
```
**Response data includes:** `order_id`, `order_number`, `payment_order_id` (Razorpay, if online), full price breakdown.

---

### GET /orders  `[Auth: customer]`
Customer's order history. Params: `page`, `status`.

### GET /orders/:id  `[Auth]`
Full order details with items, delivery partner info, price breakdown.

### PATCH /orders/:id/status  `[Auth: restaurant_owner]`
Update order status (restaurant side).  
**Valid transitions:** `pending→confirmed`, `confirmed→preparing`, `preparing→ready`

### POST /orders/:id/cancel  `[Auth]`
Cancel an order (customer or restaurant).

### POST /orders/:id/reorder  `[Auth: customer]`
Re-adds the order's items to cart.

### GET /orders/:id/track  `[Public]`
Polling endpoint — returns delivery partner location, status, estimated time.

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "on_the_way",
    "delivery_partner": { "name": "Ravi", "phone": "98...", "lat": 13.08, "lng": 80.27 },
    "estimated_minutes": 12
  }
}
```

### GET /restaurants/:id/orders  `[Auth: restaurant_owner]`
All orders for a restaurant. Params: `status`, `date`, `page`.

---

## Payments

### POST /payments/initiate  `[Auth]`
**Body:** `{ "order_id": 42 }`  
Returns Razorpay `payment_order_id`, `amount`, `currency`, `key_id`.

### POST /payments/verify  `[Auth]`
**Body:**
```json
{
  "razorpay_order_id": "order_xxx",
  "razorpay_payment_id": "pay_xxx",
  "razorpay_signature": "sig_xxx"
}
```

### POST /payments/webhook
Razorpay webhook endpoint (no auth — verified by signature).

### GET /payments/:id  `[Auth]`
Payment details.

---

## Delivery (Partner App)

### GET /delivery/my-orders  `[Auth: delivery_partner]`
**Query:** `status=active` | `status=completed`

### PATCH /delivery/:id/status  `[Auth: delivery_partner]`
**Body:** `{ "status": "accepted" }`  
Flow: `assigned → accepted → picked → on_the_way → delivered`  
Earnings are recorded when status becomes `delivered`.

### PATCH /delivery/location  `[Auth: delivery_partner]`
**Body:** `{ "lat": 13.08, "lng": 80.27 }`  
Returns HTTP 204.

### PATCH /delivery/availability  `[Auth: delivery_partner]`
**Body:** `{ "available": true }`

### POST /delivery/assign/:order_id  `[Auth: admin | system]`
Assigns the nearest available partner using Haversine SQL query.

---

## Coupons

### GET /coupons
List active public coupons.

### POST /coupons/apply  `[Auth]`
Preview coupon discount.

**Body:** `{ "code": "SAVE50", "order_amount": 400 }`

**Response:**
```json
{
  "success": true,
  "data": {
    "code": "SAVE50",
    "discount_amount": 50,
    "final_amount": 350,
    "message": "₹50 saved!"
  }
}
```

### POST /coupons  `[Auth: admin]`
Create a new coupon.

---

## Subscriptions

### GET /subscriptions/status  `[Auth]`
Returns active subscription for the authenticated user/restaurant.

### POST /subscriptions/restaurant  `[Auth: restaurant_owner]`
Subscribe restaurant to a plan.

**Body:** `{ "plan": "basic" }` — plans: `basic` (₹999), `pro` (₹1999), `premium` (₹2999)

### POST /subscriptions/customer  `[Auth: customer]`
Subscribe to Aharam Plus.

**Body:** `{ "plan": "plus" }` — ₹99/month

---

## Recommendations

### GET /recommendations  `[Auth: customer]`
Personalized restaurant + item recommendations.

**Response data includes:** `time_based`, `order_history`, `trending`, `reorder_suggestion`

### GET /recommendations/trending
Trending items (public, no auth).

---

## Reviews

### POST /reviews  `[Auth: customer]`
**Body:**
```json
{
  "order_id": 42,
  "restaurant_rating": 4,
  "delivery_rating": 5,
  "comment": "Great food, fast delivery!"
}
```
Only allowed after order is `delivered`.

### GET /restaurants/:id/reviews
**Query:** `page`

---

## Addresses

### GET /addresses  `[Auth: customer]`
### POST /addresses  `[Auth: customer]`
**Body:** `{ "label": "Home", "address_line1": "...", "city": "Chennai", "pincode": "600001", "lat": 13.08, "lng": 80.27 }`
### PATCH /addresses/:id  `[Auth: customer]`
### DELETE /addresses/:id  `[Auth: customer]`
### PATCH /addresses/:id/default  `[Auth: customer]`

---

## Notifications

### GET /notifications  `[Auth]`
**Query:** `unread=1` to filter unread only.

### PATCH /notifications/:id/read  `[Auth]`

---

## Admin Endpoints  `[Auth: admin]`

| Method | Path | Description |
|--------|------|-------------|
| GET | /admin/dashboard | Platform stats |
| GET | /admin/users | All users (params: role, search, page) |
| GET | /admin/restaurants | All restaurants (params: status, search, page) |
| PATCH | /admin/restaurants/:id/approve | `{ "status": "approved"\|"rejected" }` |
| GET | /admin/orders | All orders (params: status, search, page) |
| GET | /admin/earnings | Platform earnings summary |
| GET | /admin/settlements | Restaurant settlement records |
| POST | /admin/settlements/:id/process | Mark settlement as processed |
| GET | /admin/delivery-partners | All delivery partners |
| PATCH | /admin/delivery-partners/:id/verify | `{ "status": "approved"\|"rejected" }` |
| GET | /admin/settings | Platform settings |
| PATCH | /admin/settings | Update platform settings |
| POST | /admin/coupons | Create coupon |

---

## WhatsApp Simulation

### POST /whatsapp/order
Simulate WhatsApp-style text order parsing.

**Body:** `{ "phone": "9876543210", "message": "1 chicken biryani 2 naan from Biryani House" }`

---

## Health Check

### GET /health
Returns API status and DB connectivity. No auth required.

```json
{ "success": true, "data": { "status": "ok", "db": "connected", "version": "1.0.0" } }
```

---

## Error Codes

| HTTP | Meaning |
|------|---------|
| 400 | Validation error — check `errors` field |
| 401 | Unauthenticated — token missing or expired |
| 403 | Forbidden — wrong role |
| 404 | Resource not found |
| 409 | Conflict (e.g. duplicate coupon code) |
| 422 | Business rule violation (e.g. restaurant closed) |
| 500 | Internal server error |

**Validation error format:**
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": { "email": "Invalid email address", "password": "Minimum 8 characters" }
}
```
