# 🚀 PHP Swoole SOCKS5 Proxy Server

A **high-performance SOCKS5 proxy server** built with **PHP** and **Swoole**.  
This project aims to provide a fast, efficient, and fully compliant SOCKS5 server implementation using modern asynchronous PHP capabilities.

---

## 🔧 Features

- ✅ Full support for **SOCKS5 protocol**
- 🔒 Supports **authentication methods**
- 🌐 IPv4 and IPv6 address support
- 📦 Handles **TCP and UDP** transport layers
- 🚀 High-performance TCP packet control using **Swoole coroutines**
- 📡 Supports **UDP ASSOCIATE** for UDP relaying
- 🧠 Clean and robust **SOCKS5 error handling**
- 🧪 Designed for scalability and production use

---

## 📁 Getting Started

### 🛠 Requirements

- PHP **8.1+**
- [Swoole extension](https://www.swoole.co.uk/) **v5+**
- Composer (optional for future improvements)

### ⚙️ Configuration
Clone the repository and copy the `.env.example` to configure the environment:
```bash
cp .env.example .env
```

### 📦 Composer Installation

Install required dependencies using Composer:

If you have Composer installed globally:

```bash
composer install
```


### ⚙️ Running the Proxy

To start the SOCKS5 proxy server, simply run the following command in your terminal:

```bash
php server.php
```



