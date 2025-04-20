# CopartRemocoes

[Versão PT-Br](https://github.com/lanng/CopartRemocoes/blob/main/README-PT.md)

## About This Project

CopartRemocoes is a web application **currently in operational use**, designed to efficiently manage and track the removal process of compromised (total loss) vehicles for an internal company workflow. It addresses the real-world challenge of coordinating between insurance orders and transport logistics.

When an insurance company declares a vehicle a total loss, they issue a PDF order containing details like addresses, vehicle model, plate, and ID. This application streamlines the registration process by allowing users to upload this PDF; a backend service then automatically reads the relevant information and pre-fills the form fields, significantly reducing manual data entry and potential errors.

Built with **Laravel** and **Filament PHP**, the system provides enhanced control over removal operations and offers flexibility for workers to update vehicle status remotely. Each user has a unique login, ensuring all changes and creations are logged for accountability. Uploaded PDF documents are securely stored using **AWS S3**.

This project showcases the practical application of modern web development technologies (Laravel, Filament), external service integration (AWS S3), and process automation (PDF data extraction) to solve a specific business need.

## Features

* **Vehicle Removal Tracking:** Manage and monitor the status of vehicle removals.
* **Automated Data Entry:** Extracts data (address, model, plate, ID, etc.) directly from uploaded PDF removal orders to auto-fill registration forms.
* **Cloud Storage:** Securely stores uploaded PDF documents associated with each removal record using AWS S3.
* **Internal Administration Panel:** User-friendly interface built with Filament.
* **User Auditing:** User-specific logins for tracking actions and maintaining logs.
* **Remote Accessibility:** Designed for flexibility, allowing updates from anywhere.
* **Tech Stack:** Built with Laravel, Filament PHP, and integrates with AWS S3.

## Setup Instructions

**1. Prerequisites:**

* **PHP 8.0^:** Ensure you have a compatible version of PHP installed.
* **Composer:** [Install Composer](https://getcomposer.org/download/)
* **Database:** A compatible relational database server (e.g., MySQL, PostgreSQL).
* **Poppler Utilities:** This is required for the PDF text extraction feature. Install it for your operating system:
    * **Linux (Debian/Ubuntu):**
        ```bash
        sudo apt update && sudo apt install poppler-utils
        ```
    * **Linux (Fedora/CentOS/RHEL):**
        ```bash
        sudo yum update && sudo yum install poppler-utils
        # Or using dnf
        # sudo dnf install poppler-utils
        ```
    * **macOS (using Homebrew):**
        ```bash
        brew install poppler
        ```
    * **Windows:**
        * **Using Chocolatey (Recommended):** Open PowerShell as Administrator and run:
            ```powershell
            choco install poppler
            ```
          *(You might need to install Chocolatey first: [https://chocolatey.org/install](https://chocolatey.org/install))*
        * **Manual Download:** You can find Windows binaries, but sources vary. Search for "poppler windows binaries". Ensure the installation directory containing `pdftotext.exe` is added to your system's PATH environment variable.
        * **Using WSL (Windows Subsystem for Linux):** Install via the Linux distribution running under WSL using the Linux commands above.

**2. Clone the repository:**
```bash
git clone https://github.com/lanng/CopartRemocoes.git
cd CopartRemocoes
```

**3. Install PHP Dependencies:**
```bash
composer install
```

**4. Environment Configuration:**
Copy the example environment file and configure it.
```bash
cp .env.example .env
```
* Open `.env` and update `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, etc.
* Configure AWS S3 credentials (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`).
* Ensure `FILESYSTEM_DISK` is set to `s3` (or your configured disk for PDFs).
* Or change it the `FILESYSTEM_DISK` to store in the app for testing.
* Generate the application key:
```bash
php artisan key:generate
```

**6. Database Migrations & Seeding:**
```bash
php artisan migrate
```

**7. Serve the Application:**
```bash
php artisan serve
```
Access the application, typically at `http://127.0.0.1:8000`.

## Deployment

This application is currently deployed on a VPS. Deployment steps may vary depending on the server environment (e.g., using Nginx/Apache, Supervisor for queues, ensuring necessary PDF processing libraries are installed on the server, configuring AWS credentials securely). Refer to Laravel deployment documentation for general guidance.
