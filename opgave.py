import os
import secrets
import smtplib
from email.message import EmailMessage
from pathlib import Path

from flask import Flask, flash, render_template_string, request, redirect, url_for, session
import pymysql
from datetime import date, datetime, timedelta
from werkzeug.security import generate_password_hash, check_password_hash

app = Flask(__name__)
app.secret_key = 'skole_udlaan_ekstrem_hemmelig_nøgle'
MAIL_SENDER = "privatemail19681@gmail.com"
MAIL_PASSWORD_FILE = Path(os.environ["APPDATA"]) / "SkoleUdlaan" / "gmail_app_password.txt"

# --- DATABASE KONFIGURATION ---
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': 'Niko569d_2006',  # Dit MySQL kodeord
    'database': 'skole_udlaan',
    'port': 3306,
    'cursorclass': pymysql.cursors.DictCursor
}


def ensure_bruger_password_field():
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("SHOW COLUMNS FROM brugere LIKE 'password_hash'")
            if cursor.fetchone() is None:
                cursor.execute("ALTER TABLE brugere ADD COLUMN password_hash VARCHAR(255) NULL AFTER email")
                conn.commit()

            cursor.execute("SHOW COLUMNS FROM brugere LIKE 'reset_token'")
            if cursor.fetchone() is None:
                cursor.execute("ALTER TABLE brugere ADD COLUMN reset_token VARCHAR(255) NULL AFTER password_hash")
                conn.commit()

            cursor.execute("SHOW COLUMNS FROM brugere LIKE 'reset_expires'")
            if cursor.fetchone() is None:
                cursor.execute("ALTER TABLE brugere ADD COLUMN reset_expires DATETIME NULL AFTER reset_token")
                conn.commit()
    finally:
        conn.close()


def get_db_connection():
    return pymysql.connect(**DB_CONFIG)


def ensure_default_admin():
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("SHOW TABLES LIKE 'admins'")
            if cursor.fetchone() is None:
                cursor.execute("""
                    CREATE TABLE admins (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        username VARCHAR(100) NOT NULL UNIQUE,
                        password_hash VARCHAR(255) NOT NULL
                    )
                """)
                conn.commit()

            cursor.execute("SELECT id FROM admins WHERE username = %s", ('admin',))
            if cursor.fetchone() is None:
                cursor.execute(
                    "INSERT INTO admins (username, password_hash) VALUES (%s, %s)",
                    ('admin', generate_password_hash('1234'))
                )
                conn.commit()
    finally:
        conn.close()

# --- ULTRA-AVANCERET HTML & TAILWIND DESIGN (MED LIGHT/DARK MODE OG NYE FEATURES) ---
HTML_TEMPLATE = """
<!DOCTYPE html>
<html lang="da" class="h-full" x-data="{ darkMode: (() => { try { return localStorage.getItem('darkMode') === 'true'; } catch (e) { return false; } })(), activeTab: 'dashboard', search: '' }" :class="{ 'dark': darkMode }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise IT Udlånsportal | Skole System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#eff6ff', 500: '#3b82f6', 600: '#2563eb', 900: '#1e3a8a', 950: '#0f172a' }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.17), transparent 26%),
                radial-gradient(circle at bottom right, rgba(168, 85, 247, 0.14), transparent 24%),
                linear-gradient(135deg, #f8fafc 0%, #eef2ff 42%, #f8fafc 100%);
        }
        .dark body {
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(168, 85, 247, 0.16), transparent 26%),
                linear-gradient(135deg, #020817 0%, #0f172a 42%, #020817 100%);
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(148, 163, 184, 0.22);
            box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }
        .dark .glass-panel {
            background: rgba(15, 23, 42, 0.68);
            border-color: rgba(148, 163, 184, 0.18);
            box-shadow: 0 18px 52px rgba(2, 6, 23, 0.48);
        }
        .hero-glow::before {
            content: "";
            position: absolute;
            inset: -30% auto auto -12%;
            width: 260px;
            height: 260px;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.25), transparent 60%);
            filter: blur(8px);
        }
        .hero-glow::after {
            content: "";
            position: absolute;
            right: -10%;
            top: -12%;
            width: 230px;
            height: 230px;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(168, 85, 247, 0.2), transparent 60%);
            filter: blur(10px);
        }
    </style>
</head>
<body class="h-full font-sans antialiased flex overflow-hidden bg-slate-100 dark:bg-slate-950 text-slate-800 dark:text-slate-100 transition-colors duration-300">

    <!-- SIDEBAR NAVIGATION -->
    <aside class="w-72 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 flex flex-col z-20 shadow-2xl transition-colors duration-300">
        <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center space-x-3 bg-slate-50/50 dark:bg-slate-900/50 backdrop-blur">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-500 flex items-center justify-center shadow-lg shadow-blue-500/30 text-white font-bold text-xl">
                <i class="fa-solid fa-laptop-code"></i>
            </div>
            <div>
                <h1 class="font-bold text-base tracking-tight text-slate-900 dark:text-white">IT-Udlån Pro</h1>
                <span class="text-xs text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1.5 mt-0.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> MySQL Forbundet
                </span>
            </div>
        </div>

        <nav class="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
            <p class="px-3 text-[10px] font-bold tracking-wider text-slate-400 uppercase mb-2">Hovedmenu</p>
            
            <button @click="activeTab = 'dashboard'" :class="activeTab === 'dashboard' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span>Dashboard & Oversigt</span>
            </button>

            <button @click="activeTab = 'udlaan'" :class="activeTab === 'udlaan' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200">
                <i class="fa-solid fa-right-left w-5 text-center"></i>
                <span>Registrer Nyt Udlån</span>
            </button>

            <button @click="activeTab = 'computere'" :class="activeTab === 'computere' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200">
                <i class="fa-solid fa-desktop w-5 text-center"></i>
                <span>Computere & Mus</span>
            </button>

            <button @click="activeTab = 'brugere'" :class="activeTab === 'brugere' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200">
                <i class="fa-solid fa-users-rectangle w-5 text-center"></i>
                <span>Elevregister</span>
            </button>

            <button @click="activeTab = 'adgangskoder'" :class="activeTab === 'adgangskoder' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800/60 hover:text-slate-900 dark:hover:text-white'" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl font-medium text-sm transition-all duration-200">
                <i class="fa-solid fa-key w-5 text-center"></i>
                <span>Adgangskoder</span>
            </button>
        </nav>

        <div class="p-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-950/40">
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-3 rounded-xl flex items-center justify-between">
                <div class="flex items-center space-x-3 overflow-hidden">
                    <div class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center font-bold text-xs">
                        ADM
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-xs font-semibold text-slate-900 dark:text-white truncate">Administrator</p>
                        <p class="text-[10px] text-slate-500 truncate">Port: 8080</p>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT CONTAINER -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-100 dark:bg-slate-950 transition-colors duration-300">
        
        <!-- HEADER BAR MED DARK/LIGHT MODE TOGGLE OG SØGNING -->
        <header class="h-20 bg-white/80 dark:bg-slate-900/80 backdrop-blur border-b border-slate-200 dark:border-slate-800 px-8 flex items-center justify-between z-10 transition-colors duration-300">
            <div class="flex items-center space-x-4 w-96">
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </span>
                    <input x-model="search" type="text" placeholder="Søg hurtigt i visninger..." class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 transition">
                </div>
            </div>

            <div class="flex items-center space-x-4">
                <!-- LIGHT / DARK MODE KNAP -->
                <button @click="darkMode = !darkMode; try { localStorage.setItem('darkMode', darkMode); } catch (e) {}" class="bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-300 dark:hover:bg-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shadow-sm">
                    <i :class="darkMode ? 'fa-solid fa-sun text-amber-400' : 'fa-solid fa-moon text-blue-600'"></i>
                    <span x-text="darkMode ? 'Light Mode' : 'Dark Mode'"></span>
                </button>

                <div class="flex items-center space-x-2 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 px-3.5 py-2 rounded-xl shadow-sm">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-ping"></span>
                    <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Live Database</span>
                </div>
                <a href="/logout" class="bg-rose-500 hover:bg-rose-600 text-white px-3 py-2 rounded-xl text-xs font-bold transition">
                    Log ud
                </a>
            </div>
        </header>

        <main class="flex-1 overflow-y-auto p-8 space-y-8">
            {% with messages = get_flashed_messages(with_categories=true) %}
                {% if messages %}
                <div class="space-y-2" role="alert">
                    {% for category, message in messages %}
                    <div class="rounded-xl border px-4 py-3 text-sm font-medium {{ 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' if category == 'success' else 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300' }}">
                        {{ message }}
                    </div>
                    {% endfor %}
                </div>
                {% endif %}
            {% endwith %}

            <!-- ================= TAB 1: DASHBOARD ================= -->
            <div x-show="activeTab === 'dashboard'" class="space-y-8">
                
                <div class="glass-panel hero-glow relative overflow-hidden rounded-3xl p-8 border border-blue-500/20 shadow-[0_30px_64px_rgba(37,99,235,0.12)]">
                    <div class="absolute inset-0 bg-gradient-to-r from-blue-600/10 via-indigo-500/5 to-transparent"></div>
                    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                        <div>
                            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-600/10 text-blue-700 dark:text-blue-300 border border-blue-500/20 text-[10px] font-bold uppercase tracking-[0.18em]">
                                <i class="fa-solid fa-sparkles"></i> Premium operations overview
                            </span>
                            <h2 class="mt-4 text-3xl font-black text-slate-900 dark:text-white tracking-tight">Velkommen tilbage, Administrator ⚡</h2>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400 max-w-2xl">Her er det fulde overblik over skolens computere, aktive elevlån, udestående frister og hurtige genveje til administrationen.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-2xl border border-emerald-500/20">
                                <i class="fa-solid fa-shield-check"></i>
                            </div>
                            <div class="min-w-[160px]">
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Systemstatus</p>
                                <p class="mt-1 text-base font-bold text-slate-900 dark:text-white">Driftklar</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Metrics Grid -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-sm transition">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Totale PC'er</p>
                                <h3 class="text-3xl font-black text-slate-900 dark:text-white mt-2">{{ total_pc }}</h3>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xl">
                                <i class="fa-solid fa-laptop"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-sm transition">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Ledige PC'er</p>
                                <h3 class="text-3xl font-black text-emerald-600 dark:text-emerald-400 mt-2">{{ ledige_pc }}</h3>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-xl transition">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Aktive Udlån</p>
                                <h3 class="text-3xl font-black text-indigo-600 dark:text-indigo-400 mt-2">{{ aktive_count }}</h3>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xl">
                                <i class="fa-solid fa-box-archive"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-6 rounded-2xl shadow-xl transition">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Overskredne Frister</p>
                                <h3 class="text-3xl font-black text-rose-600 mt-2">{{ forsinkede_count }}</h3>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-rose-500/10 text-rose-600 flex items-center justify-center text-xl">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aktive Udlån Tabel -->
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden transition-colors duration-300">
                    <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Aktive Udlån & Aflevering</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Oversigt over enheder, der er lånt ud til elever lige nu.</p>
                        </div>
                        <button @click="activeTab = 'udlaan'" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-4 py-2.5 rounded-xl transition shadow-sm">
                            <i class="fa-solid fa-plus mr-1.5"></i> Nyt Udlån
                        </button>
                    </div>

                    {% if aktive_lån %}
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-50 dark:bg-slate-950/50 border-b border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                    <th class="p-4 pl-6">Elevnavn</th>
                                    <th class="p-4">Klasse</th>
                                    <th class="p-4">Computernummer</th>
                                    <th class="p-4">Udlånsdato</th>
                                    <th class="p-4">Afleveringsfrist</th>
                                    <th class="p-4 pr-6 text-right">Handling / Ekstra Feature</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 text-sm">
                                {% for lån in aktive_lån %}
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                    <td class="p-4 pl-6 font-semibold text-slate-900 dark:text-white flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-800 flex items-center justify-center text-xs font-bold text-blue-600 dark:text-blue-400">
                                            {{ lån.navn[:2].upper() }}
                                        </div>
                                        {{ lån.navn }}
                                    </td>
                                    <td class="p-4 text-slate-700 dark:text-slate-300 font-medium">{{ lån.stamklasse }}</td>
                                    <td class="p-4">
                                        <span class="bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20 px-3 py-1 rounded-lg text-xs font-bold">
                                            {{ lån.computernummer }}
                                        </span>
                                    </td>
                                    <td class="p-4 text-slate-500 dark:text-slate-400 text-xs">{{ lån.udlaansdato }}</td>
                                    <td class="p-4">
                                        {% if lån.dage_over_tid > 0 %}
                                            <span class="bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 px-3 py-1 rounded-lg text-xs font-bold flex items-center gap-1.5 w-max">
                                                <i class="fa-solid fa-clock"></i> {{ lån.udloebsdato }} ({{ lån.dage_over_tid }} dage over tid!)
                                            </span>
                                        {% else %}
                                            <span class="text-slate-700 dark:text-slate-300 text-xs font-medium">{{ lån.udloebsdato }}</span>
                                        {% endif %}
                                    </td>
                                    <td class="p-4 pr-6 text-right flex items-center justify-end gap-2">
                                        <form action="{{ url_for('send_rykker', laan_id=lån.id) }}" method="POST" class="inline">
                                            <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-xl text-xs font-bold transition shadow-sm" title="Send rykker på e-mail">
                                                <i class="fa-solid fa-envelope mr-1"></i> Send rykker
                                            </button>
                                        </form>
                                        
                                        <form action="/aflever/{{ lån.id }}/{{ lån.computernummer }}" method="POST" class="inline">
                                            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-sm">
                                                <i class="fa-solid fa-check mr-1"></i> Aflever PC
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                    {% else %}
                    <div class="p-12 text-center">
                        <div class="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400 flex items-center justify-center mx-auto text-2xl mb-4">
                            <i class="fa-solid fa-folder-open"></i>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 font-medium text-sm">Ingen aktive udlån i øjeblikket.</p>
                        <p class="text-slate-400 dark:text-slate-600 text-xs mt-1">Opret et nyt udlån ovenfor for at komme i gang.</p>
                    </div>
                    {% endif %}
                </div>
            </div>

            <!-- ================= TAB 2: NYT UDLÅN ================= -->
            <div x-show="activeTab === 'udlaan'" class="space-y-6 max-w-3xl">
                <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm p-8 transition-colors duration-300">
                    <div class="flex items-center space-x-3 pb-6 border-b border-slate-200 dark:border-slate-800 mb-6">
                        <div class="w-10 h-10 rounded-xl bg-blue-600/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-file-circle-plus"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Registrer Nyt Udlån</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Koble en elev sammen med en ledig computer.</p>
                        </div>
                    </div>

                    {% if not computere %}
                    <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6 flex items-start space-x-3 text-amber-700 dark:text-amber-200 text-xs">
                        <i class="fa-solid fa-triangle-exclamation text-base mt-0.5"></i>
                        <div>
                            <span class="font-bold block text-sm">Ingen computere fundet i databasen!</span>
                            Du skal først oprette mindst én computer under fanebladet "Computere & Mus", før du kan oprette et udlån.
                        </div>
                    </div>
                    {% endif %}

                    <form action="/ny_udlaan" method="POST" class="space-y-6">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-2">Elevnummer</label>
                            <input type="text" name="elevnummer" required placeholder="F.eks. E12345" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-3 text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-2">Vælg LEDIG Computer</label>
                            <select name="computernummer" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500 transition">
                                {% set ns = namespace(found=false) %}
                                {% for pc in computere %}
                                    {% if pc.status == 'tilgængelig' %}
                                        {% set ns.found = true %}
                                        <option value="{{ pc.computernummer }}">{{ pc.computernummer }} - {{ pc.fabrikat }} {{ pc.model }} (Mus: {{ pc.mus }})</option>
                                    {% endif %}
                                {% endfor %}
                                {% if not ns.found %}
                                    <option value="" disabled selected>-- Ingen ledige computere tilgængelige --</option>
                                {% endif %}
                            </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-2">Udlånsdato</label>
                                <input type="date" name="udlaansdato" value="{{ dagens_dato }}" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider mb-2">Udløbsdato (Afleveringsfrist)</label>
                                <input type="date" name="udloebsdato" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-3 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500 transition">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3.5 rounded-xl font-bold text-sm transition shadow-sm flex items-center justify-center gap-2">
                            <i class="fa-solid fa-check"></i> Godkend og Opret Udlån
                        </button>
                    </form>
                </div>
            </div>

            <!-- ================= TAB 3: COMPUTERE & MUS ================= -->
            <div x-show="activeTab === 'computere'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Opret Computer Form -->
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm p-6 h-fit transition-colors duration-300">
                        <h3 class="font-bold text-base text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-desktop text-blue-600 dark:text-blue-400"></i> Opret Ny Enhed
                        </h3>
                        <form action="/ny_computer" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Computernummer</label>
                                <input type="text" name="computernummer" required placeholder="PC-0922" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Fabrikat</label>
                                <input type="text" name="fabrikat" required placeholder="DELL / Lenovo" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Model</label>
                                <input type="text" name="model" required placeholder="Inspiron 9300" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1.5">Mus-type</label>
                                <select name="mus" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                                    <option value="Optisk">Optisk</option>
                                    <option value="Almindelig">Almindelig</option>
                                    <option value="Nej">Nej</option>
                                </select>
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-3 rounded-xl font-bold text-xs transition shadow-sm">
                                Gem Enhed i Arkiv
                            </button>
                        </form>
                    </div>

                    <!-- Computeroversigt -->
                    <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden transition-colors duration-300">
                        <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-lg text-slate-900 dark:text-white">Computer- og Musarkiv</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">Komplet oversigt over alle maskiner på skolen.</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 dark:bg-slate-950/50 border-b border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                        <th class="p-4 pl-6">PC Nr.</th>
                                        <th class="p-4">Fabrikat & Model</th>
                                        <th class="p-4">Mus</th>
                                        <th class="p-4 pr-6">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 text-sm">
                                    {% for pc in computere %}
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition">
                                        <td class="p-4 pl-6 font-bold text-blue-600 dark:text-blue-400">{{ pc.computernummer }}</td>
                                        <td class="p-4 text-slate-900 dark:text-white font-medium">{{ pc.fabrikat }} <span class="text-slate-500 text-xs">{{ pc.model }}</span></td>
                                        <td class="p-4 text-slate-700 dark:text-slate-300 text-xs">{{ pc.mus }}</td>
                                        <td class="p-4 pr-6">
                                            {% if pc.status == 'tilgængelig' %}
                                                <span class="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20 px-2.5 py-1 rounded-lg text-xs font-bold">Ledig</span>
                                            {% else %}
                                                <span class="bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 px-2.5 py-1 rounded-lg text-xs font-bold">Udlånt</span>
                                            {% endif %}
                                        </td>
                                    </tr>
                                    {% else %}
                                    <tr>
                                        <td colspan="4" class="p-8 text-center text-slate-500 text-xs">Ingen computere oprettet endnu. Brug formularen til venstre.</td>
                                    </tr>
                                    {% endfor %}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 4: ELEVREGISTER (UTEN CPR) ================= -->
            <div x-show="activeTab === 'brugere'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Opret Elev Form -->
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm p-6 h-fit transition-colors duration-300">
                        <h3 class="font-bold text-base text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-user-plus text-blue-600 dark:text-blue-400"></i> Opret Ny Bruger
                        </h3>
                        <form action="/ny_bruger" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Elevnummer</label>
                                <input type="text" name="elevnummer" required placeholder="E1234" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Fulde Navn</label>
                                <input type="text" name="navn" required placeholder="Anders Andersen" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Adresse</label>
                                    <input type="text" name="adresse" placeholder="Skolevej 1" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Postnr & By</label>
                                    <input type="text" name="postnummer_by" placeholder="5000 Odense" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-900 dark:text-white focus:outline-none">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">E-mail</label>
                                <input type="email" name="email" required placeholder="elev@skole.dk" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Adgangskode</label>
                                <input type="password" name="password" required minlength="4" placeholder="Min. 4 tegn" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Stamklasse</label>
                                <input type="text" name="stamklasse" required placeholder="2.g TEC" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" class="w-full bg-slate-800 hover:bg-slate-700 text-white py-3 rounded-xl font-bold text-xs transition shadow-sm">
                                Opret Elev i System
                            </button>
                        </form>
                    </div>

                    <!-- Elev Oversigt -->
                    <div class="lg:col-span-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden transition-colors duration-300">
                        <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                            <h3 class="font-bold text-lg text-slate-900 dark:text-white">Elev- og Brugerregister</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Oversigt over registrerede studerende (GDPR-venligt uden CPR).</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 dark:bg-slate-950/50 border-b border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                        <th class="p-4 pl-6">Elevnr. & Navn</th>
                                        <th class="p-4">Klasse</th>
                                        <th class="p-4">E-mail</th>
                                        <th class="p-4">Adresse / By</th>
                                        <th class="p-4 pr-6">Adgangskode</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800/60 text-sm">
                                    {% for bruger in brugere %}
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition align-top">
                                        <td class="p-4 pl-6">
                                            <p class="font-bold text-slate-900 dark:text-white">{{ bruger.navn }}</p>
                                            <span class="text-xs text-blue-600 dark:text-blue-400 font-semibold">{{ bruger.elevnummer }}</span>
                                        </td>
                                        <td class="p-4 text-slate-700 dark:text-slate-300 font-medium">{{ bruger.stamklasse }}</td>
                                        <td class="p-4 text-slate-500 dark:text-slate-400 text-xs">{{ bruger.email }}</td>
                                        <td class="p-4 text-slate-500 dark:text-slate-400 text-xs">{{ bruger.adresse }}, {{ bruger.postnummer_by }}</td>
                                        <td class="p-4 pr-6">
                                            <form action="/opdater_bruger_password" method="POST" class="flex gap-2 items-center">
                                                <input type="hidden" name="elevnummer" value="{{ bruger.elevnummer }}">
                                                <input type="password" name="password" placeholder="Nyt kodeord" class="w-32 bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-lg px-2.5 py-2 text-[11px] text-slate-900 dark:text-white focus:outline-none focus:border-blue-500" minlength="4" required>
                                                <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white px-2.5 py-2 rounded-lg text-[10px] font-bold">Gem</button>
                                            </form>
                                        </td>
                                    </tr>
                                    {% else %}
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-slate-500 text-xs">Ingen elever oprettet endnu. Brug formularen til venstre.</td>
                                    </tr>
                                    {% endfor %}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 5: ADGANGSKODER ================= -->
            <div x-show="activeTab === 'adgangskoder'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm p-6 h-fit transition-colors duration-300">
                        <h3 class="font-bold text-base text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-key text-blue-600 dark:text-blue-400"></i> Administrer adgangskoder
                        </h3>
                        <form action="/opdater_bruger_password" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Vælg elev</label>
                                <select name="elevnummer" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                                    {% for bruger in brugere %}
                                        <option value="{{ bruger.elevnummer }}">{{ bruger.navn }} ({{ bruger.elevnummer }})</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Ny adgangskode</label>
                                <input type="password" name="password" required minlength="4" placeholder="Skriv ny adgangskode" class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl font-bold text-xs transition shadow-sm">
                                Gem ny adgangskode
                            </button>
                        </form>
                    </div>

                    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm p-6 h-fit transition-colors duration-300">
                        <h3 class="font-bold text-base text-slate-900 dark:text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-envelope-circle-check text-violet-600 dark:text-violet-400"></i> Send engangskode til elev
                        </h3>
                        <form action="/generer_engangskode" method="POST" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Vælg elev</label>
                                <select name="elevnummer" required class="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2.5 text-sm text-slate-900 dark:text-white focus:outline-none focus:border-blue-500">
                                    {% for bruger in brugere %}
                                        <option value="{{ bruger.elevnummer }}">{{ bruger.navn }} ({{ bruger.email }})</option>
                                    {% endfor %}
                                </select>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
                                Der sendes en bekræftelsesmail med et gyldigt link, hvor eleven selv kan vælge en ny adgangskode.
                            </p>
                            <button type="submit" class="w-full bg-violet-600 hover:bg-violet-700 text-white py-3 rounded-xl font-bold text-xs transition shadow-sm">
                                Send engangskode
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </main>
    </div>

</body>
</html>
"""

# --- BACKEND ENDPOINTS (OPADATERET TABELLER UDEN CPR) ---

@app.route('/admin_login', methods=['GET', 'POST'])
def admin_login():
    if request.method == 'POST':
        username = request.form.get('username', '').strip()
        password = request.form.get('password', '')

        conn = get_db_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute("SELECT username, password_hash FROM admins WHERE username = %s", (username,))
                admin = cursor.fetchone()
        finally:
            conn.close()

        if admin and check_password_hash(admin['password_hash'], password):
            session['admin_logged_in'] = True
            session['admin_username'] = admin['username']
            flash('Du er nu logget ind som administrator.', 'success')
            return redirect(url_for('index'))

        flash('Forkert brugernavn eller adgangskode.', 'error')

    return render_template_string("""
    <!DOCTYPE html>
    <html lang="da">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin login</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-8 shadow-2xl">
            <h1 class="text-2xl font-bold mb-2">Admin Login</h1>
            <p class="text-sm text-slate-400 mb-6">Log ind for at få adgang til systemet.</p>
            {% with messages = get_flashed_messages(with_categories=true) %}
                {% if messages %}
                    {% for category, message in messages %}
                        <div class="mb-4 rounded-lg border px-3 py-2 text-sm {{ 'border-rose-500/30 bg-rose-500/10 text-rose-300' if category == 'error' else 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' }}">{{ message }}</div>
                    {% endfor %}
                {% endif %}
            {% endwith %}
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-400 mb-1">Brugernavn</label>
                    <input type="text" name="username" value="admin" required class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-400 mb-1">Adgangskode</label>
                    <input type="password" name="password" value="1234" required class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-white focus:border-blue-500 focus:outline-none">
                </div>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 font-bold text-white hover:bg-blue-500">Log ind</button>
            </form>
        </div>
    </body>
    </html>
    """)


@app.route('/logout')
def logout():
    session.pop('admin_logged_in', None)
    session.pop('admin_username', None)
    flash('Du er logget ud.', 'success')
    return redirect(url_for('admin_login'))


@app.route('/')
def index():
    if not session.get('admin_logged_in'):
        return redirect(url_for('admin_login'))

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("SELECT COUNT(*) as total FROM computere")
            total_pc = cursor.fetchone()['total']
            
            cursor.execute("SELECT COUNT(*) as ledige FROM computere WHERE status = 'tilgængelig'")
            ledige_pc = cursor.fetchone()['ledige']
            
            cursor.execute("SELECT COUNT(*) as aktive FROM udlaan WHERE afleveret = 0")
            aktive_count = cursor.fetchone()['aktive']
            
            cursor.execute("SELECT COUNT(*) as forsinkede FROM udlaan WHERE afleveret = 0 AND udloebsdato < CURDATE()")
            forsinkede_count = cursor.fetchone()['forsinkede']

            cursor.execute("""
                SELECT u.id, b.navn, b.stamklasse, b.email, u.elevnummer, u.computernummer, u.udlaansdato, u.udloebsdato,
                       DATEDIFF(CURDATE(), u.udloebsdato) as dage_over_tid
                FROM udlaan u 
                JOIN brugere b ON u.elevnummer = b.elevnummer 
                WHERE u.afleveret = 0
            """)
            aktive_lån = cursor.fetchall()

            cursor.execute("SELECT * FROM computere")
            computere = cursor.fetchall()

            cursor.execute("SELECT * FROM brugere")
            brugere = cursor.fetchall()
    finally:
        conn.close()
    
    return render_template_string(HTML_TEMPLATE, 
                                  total_pc=total_pc, 
                                  ledige_pc=ledige_pc, 
                                  aktive_count=aktive_count, 
                                  forsinkede_count=forsinkede_count,
                                  aktive_lån=aktive_lån,
                                  computere=computere,
                                  brugere=brugere,
                                  dagens_dato=date.today().isoformat())

@app.route('/rykker/<int:laan_id>', methods=['POST'])
def send_rykker(laan_id):
    try:
        gmail_app_password = MAIL_PASSWORD_FILE.read_text(encoding="utf-8").strip().replace(" ", "")
    except FileNotFoundError:
        flash("Opret gmail_app_password.txt ved siden af opgave.py, og indsæt Gmail-appadgangskoden.", "error")
        return redirect(url_for('index'))
    except OSError:
        app.logger.exception("Kunne ikke læse Gmail-appadgangskoden fra %s", MAIL_PASSWORD_FILE)
        flash("Gmail-konfigurationsfilen kunne ikke læses.", "error")
        return redirect(url_for('index'))

    if not gmail_app_password:
        flash("Skriv Gmail-appadgangskoden i gmail_app_password.txt, og prøv igen.", "error")
        return redirect(url_for('index'))

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                SELECT b.navn, b.email, u.computernummer, u.udloebsdato
                FROM udlaan u
                JOIN brugere b ON u.elevnummer = b.elevnummer
                WHERE u.id = %s AND u.afleveret = 0
            """, (laan_id,))
            lån = cursor.fetchone()
    finally:
        conn.close()

    if not lån:
        flash("Udlånet blev ikke fundet eller er allerede afleveret.", "error")
        return redirect(url_for('index'))

    if not lån["email"]:
        flash("Rykker-mailen blev ikke sendt, fordi eleven ikke har en registreret e-mailadresse.", "error")
        return redirect(url_for('index'))

    message = EmailMessage()
    message["Subject"] = "Påmindelse om aflevering af computer"
    message["From"] = MAIL_SENDER
    message["To"] = lån["email"]
    message.set_content(
        f"Hej {lån['navn']},\n\n"
        f"Dette er en venlig påmindelse om, at du har lånt computer "
        f"{lån['computernummer']}.\n"
        f"Computeren skal afleveres senest {lån['udloebsdato']}.\n\n"
        "Hvis du allerede har afleveret computeren, kan du se bort fra denne mail.\n\n"
        "Venlig hilsen\nIT-Udlån"
    )

    try:
        with smtplib.SMTP("smtp.gmail.com", 587, timeout=15) as server:
            server.starttls()
            server.login(MAIL_SENDER, gmail_app_password)
            server.send_message(message)
    except (OSError, smtplib.SMTPException) as exc:
        app.logger.exception("Kunne ikke sende rykker-mail for udlån %s", laan_id)
        flash(f"Rykker-mailen kunne ikke sendes: {exc}", "error")
        return redirect(url_for('index'))

    flash(f"Rykker-mailen blev sendt til {lån['email']}.", "success")
    return redirect(url_for('index'))

def send_engangskode_mail(student_email, student_name, token):
    gmail_app_password = MAIL_PASSWORD_FILE.read_text(encoding="utf-8").strip().replace(" ", "")
    if not gmail_app_password:
        raise ValueError("Gmail app password is empty")

    reset_link = f"http://localhost:8080/skift_adgangskode/{token}"
    message = EmailMessage()
    message["Subject"] = "Skift din adgangskode til IT-Udlån"
    message["From"] = MAIL_SENDER
    message["To"] = student_email
    message.set_content(
        f"Hej {student_name},\n\n"
        f"Du har bedt om at ændre din adgangskode til IT-Udlån.\n"
        f"Klik på følgende link for at vælge en ny adgangskode:\n\n{reset_link}\n\n"
        "Linket er gyldigt i 24 timer. Hvis du ikke har anmodet om dette, kan du se bort fra denne mail.\n\n"
        "Venlig hilsen\nIT-Udlån"
    )

    with smtplib.SMTP("smtp.gmail.com", 587, timeout=15) as server:
        server.starttls()
        server.login(MAIL_SENDER, gmail_app_password)
        server.send_message(message)


@app.route('/generer_engangskode', methods=['POST'])
def generer_engangskode():
    elevnr = request.form.get('elevnummer')
    if not elevnr:
        flash("Vælg en elev først.", "error")
        return redirect(url_for('index'))

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "SELECT elevnummer, navn, email FROM brugere WHERE elevnummer = %s",
                (elevnr,)
            )
            bruger = cursor.fetchone()
    finally:
        conn.close()

    if not bruger or not bruger['email']:
        flash("Eleven findes ikke eller har ingen e-mailadresse registreret.", "error")
        return redirect(url_for('index'))

    token = secrets.token_urlsafe(32)
    expires_at = (datetime.utcnow() + timedelta(hours=24)).strftime('%Y-%m-%d %H:%M:%S')

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE brugere SET reset_token = %s, reset_expires = %s WHERE elevnummer = %s",
                (token, expires_at, elevnr)
            )
            conn.commit()
    finally:
        conn.close()

    try:
        send_engangskode_mail(bruger['email'], bruger['navn'], token)
        flash(f"En engangskode blev sendt til {bruger['email']}.", "success")
    except (FileNotFoundError, OSError, ValueError, smtplib.SMTPException) as exc:
        app.logger.exception("Kunne ikke sende engangskode til elev %s", elevnr)
        flash(f"Engangskoden kunne ikke sendes: {exc}", "error")

    return redirect(url_for('index'))


@app.route('/skift_adgangskode/<token>', methods=['GET', 'POST'])
def skift_adgangskode(token):
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "SELECT elevnummer, navn, email, reset_expires FROM brugere WHERE reset_token = %s",
                (token,)
            )
            bruger = cursor.fetchone()
    finally:
        conn.close()

    if not bruger:
        return """
        <!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Ugyldigt link</title><script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
        <div class="w-full max-w-lg rounded-2xl border border-rose-500/30 bg-slate-900 p-8 text-center">
            <h1 class="text-2xl font-bold text-rose-300 mb-3">Ugyldigt eller udløbet link</h1>
            <p class="text-slate-300 text-sm">Linket til at ændre adgangskoden er enten ugyldigt eller udløbet.</p>
        </div>
        </body></html>
        """

    expires = bruger['reset_expires']
    if isinstance(expires, str):
        expires_dt = datetime.strptime(expires, '%Y-%m-%d %H:%M:%S')
    else:
        expires_dt = expires

    if expires_dt < datetime.utcnow():
        conn = get_db_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute("UPDATE brugere SET reset_token = NULL, reset_expires = NULL WHERE elevnummer = %s", (bruger['elevnummer'],))
                conn.commit()
        finally:
            conn.close()
        return """
        <!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Link udløbet</title><script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
        <div class="w-full max-w-lg rounded-2xl border border-amber-500/30 bg-slate-900 p-8 text-center">
            <h1 class="text-2xl font-bold text-amber-300 mb-3">Linket er udløbet</h1>
            <p class="text-slate-300 text-sm">Bed administratoren om at sende et nyt link til at ændre adgangskoden.</p>
        </div>
        </body></html>
        """

    if request.method == 'POST':
        new_password = request.form.get('password', '').strip()
        if len(new_password) < 4:
            return """
            <!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Adgangskode for kort</title><script src="https://cdn.tailwindcss.com"></script></head>
            <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
            <div class="w-full max-w-md rounded-2xl border border-rose-500/30 bg-slate-900 p-8">
                <h1 class="text-2xl font-bold mb-3">Adgangskoden er for kort</h1>
                <p class="text-sm text-slate-300 mb-4">Adgangskoden skal være mindst 4 tegn.</p>
                <a href="/skift_adgangskode/{token}" class="inline-block rounded-xl bg-blue-600 px-4 py-2 font-bold text-white">Prøv igen</a>
            </div>
            </body></html>
            """.format(token=token)

        password_hash = generate_password_hash(new_password)
        conn = get_db_connection()
        try:
            with conn.cursor() as cursor:
                cursor.execute(
                    "UPDATE brugere SET password_hash = %s, reset_token = NULL, reset_expires = NULL WHERE elevnummer = %s",
                    (password_hash, bruger['elevnummer'])
                )
                conn.commit()
        finally:
            conn.close()

        return """
        <!DOCTYPE html><html lang="da"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Adgangskode ændret</title><script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
        <div class="w-full max-w-lg rounded-2xl border border-emerald-500/30 bg-slate-900 p-8 text-center">
            <h1 class="text-2xl font-bold text-emerald-300 mb-3">Adgangskoden er nu ændret</h1>
            <p class="text-slate-300 text-sm">Du kan nu logge ind med din nye adgangskode.</p>
        </div>
        </body></html>
        """

    return """
    <!DOCTYPE html>
    <html lang="da">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Skift adgangskode</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-slate-950 flex items-center justify-center text-white">
        <div class="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-8 shadow-2xl">
            <h1 class="text-2xl font-bold mb-2">Skift adgangskode</h1>
            <p class="text-sm text-slate-400 mb-6">Hej {{ bruger['navn'] }}. Vælg en ny adgangskode til dit elevlogin.</p>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs uppercase tracking-wide text-slate-400 mb-1">Ny adgangskode</label>
                    <input type="password" name="password" required minlength="4" class="w-full rounded-xl border border-slate-700 bg-slate-800 px-3 py-2.5 text-white focus:border-blue-500 focus:outline-none">
                </div>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-3 font-bold text-white hover:bg-blue-500">Gem ny adgangskode</button>
            </form>
        </div>
    </body>
    </html>
    """.replace("{{ bruger['navn'] }}", bruger['navn'])


@app.route('/ny_udlaan', methods=['POST'])
def ny_udlaan():
    elevnr = request.form.get('elevnummer')
    pcnr = request.form.get('computernummer')
    udlaansdato = request.form.get('udlaansdato')
    udloebsdato = request.form.get('udloebsdato')
    
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                INSERT INTO udlaan (elevnummer, computernummer, udlaansdato, udloebsdato, afleveret) 
                VALUES (%s, %s, %s, %s, 0)
            """, (elevnr, pcnr, udlaansdato, udloebsdato))
            cursor.execute("UPDATE computere SET status = 'udlånt' WHERE computernummer = %s", (pcnr,))
            conn.commit()
    finally:
        conn.close()
    return redirect(url_for('index'))

@app.route('/aflever/<int:laan_id>/<pcnr>', methods=['POST'])
def aflever(laan_id, pcnr):
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("UPDATE udlaan SET afleveret = 1 WHERE id = %s", (laan_id,))
            cursor.execute("UPDATE computere SET status = 'tilgængelig' WHERE computernummer = %s", (pcnr,))
            conn.commit()
    finally:
        conn.close()
    return redirect(url_for('index'))

@app.route('/ny_computer', methods=['POST'])
def ny_computer():
    pcnr = request.form.get('computernummer')
    fabrikat = request.form.get('fabrikat')
    model = request.form.get('model')
    mus = request.form.get('mus')
    
    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                INSERT INTO computere (computernummer, fabrikat, model, mus, status) 
                VALUES (%s, %s, %s, %s, 'tilgængelig')
            """, (pcnr, fabrikat, model, mus))
            conn.commit()
    finally:
        conn.close()
    return redirect(url_for('index'))

@app.route('/opdater_bruger_password', methods=['POST'])
def opdater_bruger_password():
    elevnr = request.form.get('elevnummer')
    ny_kode = request.form.get('password', '').strip()

    if not elevnr or len(ny_kode) < 4:
        flash("Angiv en gyldig elev og en adgangskode på mindst 4 tegn.", "error")
        return redirect(url_for('index'))

    password_hash = generate_password_hash(ny_kode)

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute(
                "UPDATE brugere SET password_hash = %s WHERE elevnummer = %s",
                (password_hash, elevnr)
            )
            conn.commit()
    finally:
        conn.close()

    flash(f"Adgangskoden for elev {elevnr} blev opdateret.", "success")
    return redirect(url_for('index'))


@app.route('/ny_bruger', methods=['POST'])
def ny_bruger():
    elevnr = request.form.get('elevnummer')
    navn = request.form.get('navn')
    adresse = request.form.get('adresse')
    postnummer_by = request.form.get('postnummer_by')
    email = request.form.get('email')
    stamklasse = request.form.get('stamklasse')
    password = request.form.get('password', '').strip()

    if not elevnr or not navn or not email or not stamklasse or len(password) < 4:
        flash("Alle felter skal være udfyldt, og adgangskoden skal være mindst 4 tegn.", "error")
        return redirect(url_for('index'))

    password_hash = generate_password_hash(password)

    conn = get_db_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""
                INSERT INTO brugere (elevnummer, navn, adresse, postnummer_by, email, stamklasse, password_hash) 
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (elevnr, navn, adresse, postnummer_by, email, stamklasse, password_hash))
            conn.commit()
    finally:
        conn.close()
    flash(f"Eleven {navn} blev oprettet med en ny adgangskode.", "success")
    return redirect(url_for('index'))


ensure_bruger_password_field()
ensure_default_admin()

if __name__ == '__main__':
    print("🚀 Starter IT-portal med Light/Dark mode på http://localhost:8080 ...")
    app.run(host='0.0.0.0', port=8080, debug=True)