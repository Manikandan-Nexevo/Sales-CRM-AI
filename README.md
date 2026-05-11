 ## Project Overview

This project is a role-based B2B marketplace system that manages products plans, RFQs, and orders.
Admin controls configurations and approvals, Sales manages regions and licenses, Resellers handle RFQs and orders, and End Customers request quotes and purchase products.
Authentication is handled via Auth0 with secure, role-based access

## Key Integration ...

Multi-Tenant Database Support
Role & Permission Management
Admin & Sales Representative Management
Lead Management System
Follow-up & Reminder Tracking
Customer & Contact Management
AI Assistant Integration
GROQ AI Integration
WhatsApp API Integration
Google Meet Integration

## Tech Stack

Framework: Laravel (v10)
Language: PHP (8.1.31)
Database: MySQL
API Style: REST APIs
Server: Apache

## Project Structure

app/ -> Core application logic (Controllers, Models, Helpers, Services)
config/ -> Application configuration files
routes/ -> API route definitions
storage/ -> Logs, app/public/products
public/ -> Entry point (index.php)
.env -> Environment configuration

## Installation & Setup

## Clone the Repository

git clone <repository-url>
cd project-name

## Install Dependencies

composer install

## Environment Configuration

cp .env.example .env
php artisan key:generate

## Update .env Configuration ...
APP_NAME="Nexevo Sales CRM"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000 (Product Backend URL)

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=username
DB_USERNAME=root
DB_PASSWORD=password

TENANT_DB_HOST=127.0.0.1
TENANT_DB_PORT=3306
TENANT_DB_USERNAME=username
TENANT_DB_PASSWORD=password

FRONTEND_URL=http://localhost:5174 (Production Frontend URL)

CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:5174  (Production Frontend & Backend URL)
 
## GROQ AI Integration ...

GROQ_API_KEY=your_groq_api_key
GROQ_MODEL=llama-3.1-8b-instant

## WhatsApp API Integration ...

WHATSAPP_API_TOKEN=your_whatsapp_token
WHATSAPP_PHONE_ID=your_phone_id

## Google Login Integration ...

GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=http://localhost:8000/api/google/callback  (Production Backend URL)

## Mail Configuration ...

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=nex****@gamil.com
MAIL_PASSWORD=hj***********
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="nex****@gamil.com"
MAIL_FROM_NAME="${APP_NAME}"


## Run the Application

php artisan serve

## Deployment
Server Requirements

PHP version >= 8.1.32
Composer
MySQL version >= 8.0.42
phpmyadmin version >= 5.2.1
Apache/Nginx

## Deployment Steps

Upload project to server
Configure .env
Run composer install
Set permissions for storage and bootstrap/cache
   (chmod -R 775 storage bootstrap/cache)

## Cache & Optimization

php artisan optimize
php artisan config:clear
php artisan route:clear
php artisan cache:clear

## Common Issues

500 Error: Check storage permissions
Composer error: Ensure correct PHP version
DB connection failed: Verify .env credentials

## Developer Notes

Follow MVC Architecture
Use Form Request Validation
Maintain standard API response structure
Use Service Classes for reusable business logic
Follow REST API best practices
Maintain proper activity logging
Optimize database queries for better performance
Secure all APIs using authentication middleware
