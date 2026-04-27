# Primelink Real Estate Management System (REMS)

A premium, high-fidelity web application for real-estate management, built with a modern tech stack and focusing on visual excellence, performance, and advanced searchability.

## 🚀 Vision

To provide property managers and administrative teams with a sophisticated, intuitive toolset for managing property portfolios, tenant relations, and financial reporting with an uncompromising premium aesthetic.

## 🛠 Tech Stack

- **Framework**: [Next.js 15 (App Router)](https://nextjs.org/)
- **Styling**: [Tailwind CSS v4](https://tailwindcss.com/)
- **Language**: [TypeScript](https://www.typescriptlang.org/)
- **Icons**: [Lucide React](https://lucide.dev/)
- **Animation**: Native Tailwind transitions & Glassmorphism effects

## 📦 Key Features

- **Global Search (CMD+K)**: Instant universal search for properties, tenants, and records.
- **Maintenance Management**: Interactive service request submission with automated vendor assignment feedback.
- **Financial Analytics**: High-fidelity reporting dashboards for occupancy and revenue with CSV export capabilities.
- **Document Repository**: Centralized file management for leases and insurance policies.
- **RBAC (Role-Based Access Control)**: Secure user management with detailed audit logs.
- **Premium UI**: Custom-built glassmorphism components, dark mode optimization, and high-readability typography (Outfit/Inter).

## 📂 Project Architecture

```text
/src
  /app          # Next.js App Router (Pages & Layouts)
  /components   # Premium UI Components (Dashboard, GlobalSearch, Sidebar)
  /lib          # Core Utilities (Mock Data, Export logic)
  /hooks        # Custom React Hooks
/public         # Static Assets
```

## 🛠 Getting Started

### Prerequisites

- Node.js (v18.x or higher)
- npm or yarn

### Installation

1. Clone the repository:
   ```bash
   git clone [repository-url]
   ```
2. Install dependencies:
   ```bash
   npm install
   ```
3. Run the development server:
   ```bash
   npm run dev
   ```
4. Open [http://localhost:3000](http://localhost:3000) in your browser.

## 🚀 Deployment Guide (Vercel)

This project is optimized for deployment on Vercel:

1. Connect your GitHub repository to Vercel.
2. The system will automatically detect Next.js settings.
3. No environment variables are strictly required for the demo version, as it uses high-fidelity mock data.
4. Click **Deploy**.

## ⚖️ License

Proprietary - Primelink Management System.
