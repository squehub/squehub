# 🐿️ Squehub PHP Framework

**Squehub** is a lightweight, flexible, and developer-friendly PHP framework for building web applications with modern tools and clean architecture. Designed for speed, simplicity, and extensibility.

---

## 🚀 Features

- 🧭 Simple Routing System  
- 📦 MVC Architecture  
- 🧠 Blade-like Templating Engine  
- 🔐 CSRF Protection  
- 🧰 Built-in CLI Tool  
- 🧱 Middleware Support  
- 🧪 Custom Components & Packages  

---

## 📦 Installation

You can install Squehub using Composer:

```bash
composer create-project squehub/squehub myproject
Replace myproject with your desired folder name.

or 

git clone https://github.com/squehub/squehub.git your-project
cd your-project
composer install


🛠 Usage
Start the development server:
        php squehub start

Create a controller:
        php squehub make:controller UserController

Run migrations:
        php squehub migrate

Make a middleware:
        php squehub make:middleware AuthMiddleware

Dump sample data:
        php squehub make:dumpper AdminUserDumper

View all commands:
        php squehub help

📁 Project Structure

.
├── app/                                  # Application source files
│   ├── Clis/                             # CLI command handlers
│   │   ├── dump/                         # Commands for data dumping/seeding
│   │   │   └── dump.php                  # Main dumper entry
│   │   ├── make/                         # Commands for generating boilerplate code
│   │   │   ├── MakeController.php        # Generate a new controller
│   │   │   ├── MakeDumper.php            # Generate a new data dumper
│   │   │   ├── MakeMiddleware.php        # Generate a new middleware class
│   │   │   ├── MakeMigration.php         # Generate a new migration file
│   │   │   └── MakeModel.php             # Generate a new model
│   │   └── clis.php                      # CLI command entry file
│
│   ├── Components/                       # Reusable logic components
│   │   ├── ControlStructuresComponent.php # Handles @if, @foreach, etc.
│   │   ├── DateTimeComponent.php         # Blade-like date/time directives
│   │   └── Notification.php              # Notification handler logic
│
│   ├── Core/                             # Core framework classes
│   │   ├── Exceptions/                   # Custom error/exception handlers
│   │   │   ├── CustomPrettyPageHandler.php # Pretty error page handler
│   │   │   └── debug.php                 # Debug error page
│   │   ├── controller.php                # Base controller class
│   │   ├── Database.php                  # Database connection class
│   │   ├── Dumper.php                    # Dumper base class
│   │   ├── Helper.php                    # Global helper functions
│   │   ├── Mail.php                      # Mail sending utility
│   │   ├── MiddlewareHandler.php         # Middleware runner
│   │   ├── Model.php                     # Base model class (ORM)
│   │   ├── Notification.php              # Notification trigger
│   │   ├── Service.php                   # Base service logic
│   │   ├── Task.php                      # Scheduled task base
│   │   ├── Verification.php              # Verification code logic
│   │   └── View.php                      # View rendering engine
│
│   └── Routes/
│       └── web.php                       # Default application routes
│
├── assets/                               # Frontend assets (CSS, JS, images)
│
├── config/                               # App-wide configuration files
│   ├── debug.php                         # Debug mode settings
│   └── mail.php                          # Mail server settings
│
├── database/
│   ├── dumper/                           # Seed/dump classes
│   ├── migrations/                       # Database migration scripts
│   └── mg.sql                            # Optional raw SQL dump
│
├── project/                              # App-specific logic
│   ├── controllers/                      # User-defined controllers
│   ├── Middleware/                       # User-defined middleware
│   ├── Models/                           # User-defined models
│   ├── Package/                          # Custom packages
│   └── Routes/                           # User-defined route files
│
├── public/                               # 
│   ├── assets/                           # 
│   ├── .htaccess/                        #
│   └── index.php/ 
├── scripts/                              # Setup and automation scripts
│   └── message/                          # Custom CLI messages
│
├── storage/
│   └── backups/                          # Backups and local storage
│       └── dev/                          # Development backup dumps
│
├── vendor/                               # Composer-managed dependencies
│
├── views/                                # Blade-like UI templates
│   ├── default/
│   │   └── error/
│   │       ├── 404.php                   # Plain 404 error view
│   │       ├── 404.squehub.php           # Custom 404 error view
│   │       ├── 500.php                   # Plain 500 error view
│   │       └── 500.squehub.php           # Custom 500 error view
│   └── home/
│       ├── welcome.squehub.php           # Main homepage view
│       └── welcome2.squehub.php          # Alternate homepage view
│
├── .env/                                 # Environment variable config
├── .gitignore                            # Git ignored files list
├── .htaccess                             # Apache rewrite rules
├── bootstrap.php                         # App bootstrap loader
├── composer.json                         # Composer package definitions
├── composer.lock                         # Composer lockfile
├── config.php                            # Global configuration entry
├── index.php                             # Entry point of the app (public)
├── Router.php                            # Routing engine
├── squehub                               # CLI launcher file
└── README.md                             # Project overview and docs


🧪 Contributing
Feel free to fork and contribute to Squehub!
Use pull requests to submit patches or improvements.

📄 License
Squehub is open-source and available under the MIT License.