# 🦆 DuckEggAllocationSystem-BalutOrChick

## 📌 Description

DuckEggAllocationSystem-BalutOrChick is a web-based management system built using PHP and JavaScript that manages duck egg allocation into two production categories:

* 🥚 Balut Production
* 🐣 Chick Hatching

This system helps monitor egg distribution, user roles, and overall production management in a structured and organized way.

---

## 🎯 Project Purpose

This system is designed to:

* Track duck egg allocation and distribution
* Categorize eggs into balut or chick production
* Manage users with role-based access (Admin, Manager, User)
* Handle user records with CRUD operations
* Organize system structure using a controller-based architecture
* Provide a clean and role-specific dashboard interface

---

## 📂 Project Structure

```bash
DuckEggAllocationSystem-BalutOrChick
│
├── assets/                 # CSS and JavaScript files per role
│   ├── admin/
│   ├── manager/
│   ├── user/
│   └── users/
│
├── controller/             # System logic and request handling
│   ├── auth/
│   ├── user-create.php
│   ├── user-update.php
│   ├── user-delete.php
│   ├── user-view.php
│   └── user-export.php
│
├── db/                     # Database scripts
│   ├── schema.sql
│   ├── insert.sql
│   └── db_delete.sql
│
├── model/                  # Database configuration
│   └── config.php
│
├── view/                   # UI pages (role-based dashboards)
│   ├── admin/
│   ├── manager/
│   ├── user/
│   └── users/
│
├── index.php               # Entry point of the system
├── index.js                # JavaScript entry script
├── package.json            # Project dependencies
├── package-lock.json
├── collaborators.txt
└── branch_guide.txt
```

## 🏗 System Architecture

The project follows the *MVC (Model-View-Controller)* architecture:

- *Model* – Handles data and database logic  
- *View* – Handles user interface and display  
- *Controller* – Handles system logic and connects Model & View  
