# IP-Symcon Module // Grafana2026

## Documentation

> **Note (Fork and Maintenance)**  
> This module is a **fork of the original IP-Symcon Grafana module by user *1007***.  
> The codebase was **modified and is maintained by AAGAAG** to restore compatibility with modern Grafana versions and the current *simpod JSON datasource*, and to fix multiple long‑standing bugs in the original implementation.

---

## Table of Contents

1. [Functionality](#1-functionality)  
2. [System Requirements](#2-system-requirements)  
3. [Installation](#3-installation)  
4. [Configuration](#4-configuration)  
5. [Grafana Usage](#5-grafana-usage)  
6. [Grafana Tips](#6-grafana-tips)  
7. [Changelog](#7-changelog)  
8. [To‑Do List](#8-to-do-list)

---

## 1. Functionality

This module provides **direct access from Grafana to all logged IP‑Symcon variables**.

All variables that are logged via the IP‑Symcon Archive Control are **automatically exposed as Grafana metrics** and can be selected directly in Grafana panels without any manual mapping or configuration.

---

## 2. System Requirements

- IP‑Symcon **version 4.x or newer**
- A working **Grafana installation**
- Grafana datasource plugin **“JSON by simpod”** (current versions supported)

---

## 3. Installation

Add the module repository via the **IP‑Symcon core instance “Module Control”**:

```
https://github.com/aagaag/aagaag_grafana
```

After updating the module list, create a new instance of **Grafana2026**.

---

## 4. Configuration

The module is located under the **Core Instances** in IP‑Symcon.

You may configure a **username and password** for HTTP Basic Authentication.  
These credentials **must match** the settings in the Grafana datasource configuration (JSON / simpod datasource → *Basic Auth*).

Authentication is optional.  
Please note that Grafana’s datasource test may still report *“Data source is working”* even if authentication is incorrect, because it only tests connectivity, not authorization.

For debugging purposes, the instance can be inspected via the **Core Instances** debug output.

---

## 5. Grafana Usage

### Datasource configuration

Use the **simpod JSON datasource** with the following URL:

```
http://<symcon-host>:3777/hook/Grafana2026
```

Metrics will automatically appear in the **Metric** dropdown.

### Creating graphs

1. Log in to Grafana (default port **3000**)
2. Create a new dashboard
3. Add one or more panels
4. Add a query and select the **JSON** datasource
5. Choose a metric from the dropdown
6. The first part of the metric string is the variable ID
7. The second part is used as the legend label (editable)

---

### Aggregation and Payload options

For each metric, the **Payload** field can be used to define aggregation behavior as JSON.

Aggregation levels:

- **0** – Hourly aggregation  
- **1** – Daily aggregation  
- **2** – Weekly aggregation  
- **3** – Monthly aggregation  
- **4** – Yearly aggregation  
- **5** – 5‑minute aggregation  
- **6** – 1‑minute aggregation  
- **99** – No aggregation (maximum resolution)

### TimeOffset

Time‑shifted comparisons can be performed using:

```json
{"TimeOffset": 2592000}
```

This shifts the query by **30 days into the past**.

---

## 6. Grafana Tips

- **Do not edit `defaults.ini`**.  
  Copy it to `custom.ini` or `grafana.ini` instead.

- Restart the Grafana service after changes.

### Embedding Grafana in WebFront / IPSView

Recommended settings in `grafana.ini`:

```
allow_embedding = true
cookie_samesite = lax
```

Restart Grafana afterwards.

For Chrome ≥ 80, `cookie_samesite = none` no longer works reliably; use `lax`.

If users are configured with login only, a one‑time authentication dialog may appear in WebFront/IPSView.

For static graphs without time selection, use **Share → Panel → Embed**.

Custom background styling can be achieved via an external CSS file referenced in `index.html`.

---

## 7. Changelog

### Original module (1007)

- **1.0** – Initial release  
- **1.1** – Bug fixes and documentation improvements  

### Grafana2026 fork (Adriano Aguzzi)

- Restored compatibility with modern Grafana versions
- Added full support for the `/metrics` endpoint
- Fixed empty metric dropdown behavior
- Corrected `/query` endpoint routing and response format
- Eliminated PHP warnings and fatal errors
- Clean separation from the original module to avoid conflicts
- Robust handling of aggregation, payload, and time offsets

---

## 8. To‑Do List

- Further documentation improvements
- Additional usage examples and screenshots
- Optional performance optimizations for very large archives
