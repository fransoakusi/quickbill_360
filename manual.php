<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QuickBill 305 - User Manual</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 2rem 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .header p {
            text-align: center;
            font-size: 1.1rem;
            opacity: 0.9;
        }

        /* Navigation Styles */
        .nav-container {
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav {
            padding: 1rem 0;
        }

        .nav-toggle {
            display: none;
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            border-radius: 5px;
        }

        .nav-menu {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }

        .nav-menu a {
            color: #2c3e50;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .nav-menu a:hover {
            background: #3498db;
            color: white;
        }

        /* Main Content */
        .main-content {
            background: white;
            margin: 2rem auto;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        /* Table of Contents */
        .toc {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            margin-bottom: 3rem;
        }

        .toc h2 {
            color: #2c3e50;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #3498db;
        }

        .toc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .toc-section {
            background: white;
            padding: 1rem;
            border-radius: 5px;
            border-left: 4px solid #3498db;
        }

        .toc-section h3 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }

        .toc-section ul {
            list-style: none;
        }

        .toc-section ul li {
            margin: 0.25rem 0;
        }

        .toc-section ul li a {
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .toc-section ul li a:hover {
            color: #3498db;
            text-decoration: underline;
        }

        /* Section Styles */
        .section {
            margin-bottom: 3rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid #eee;
        }

        .section:last-child {
            border-bottom: none;
        }

        .section h2 {
            color: #2c3e50;
            font-size: 2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid #3498db;
        }

        .section h3 {
            color: #34495e;
            font-size: 1.5rem;
            margin: 2rem 0 1rem 0;
            padding-left: 1rem;
            border-left: 4px solid #3498db;
        }

        .section h4 {
            color: #2c3e50;
            font-size: 1.2rem;
            margin: 1.5rem 0 0.75rem 0;
        }

        .section p {
            margin-bottom: 1rem;
            text-align: justify;
        }

        .section ul, .section ol {
            margin-bottom: 1rem;
            padding-left: 2rem;
        }

        .section li {
            margin-bottom: 0.5rem;
        }

        /* Feature Cards */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 2rem 0;
        }

        .feature-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .feature-card h4 {
            color: #3498db;
            margin-bottom: 1rem;
        }

        /* Process Steps */
        .steps {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding: 1rem;
            background: white;
            border-radius: 5px;
            border-left: 4px solid #3498db;
        }

        .step-number {
            background: #3498db;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .step-content h5 {
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        /* Code/Example Blocks */
        .example {
            background: #2c3e50;
            color: #ecf0f1;
            padding: 1rem;
            border-radius: 5px;
            font-family: 'Courier New', monospace;
            margin: 1rem 0;
            overflow-x: auto;
        }

        /* Alert Boxes */
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin: 1rem 0;
            border-left: 4px solid;
        }

        .alert-info {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }

        .alert-warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }

        .alert-success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #3498db;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 1.2rem;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .back-to-top.visible {
            opacity: 1;
            visibility: visible;
        }

        .back-to-top:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        /* Footer */
        .footer {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 2rem 0;
            margin-top: 3rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }

            .nav-toggle {
                display: block;
                margin-bottom: 1rem;
            }

            .nav-menu {
                display: none;
                flex-direction: column;
                width: 100%;
            }

            .nav-menu.active {
                display: flex;
            }

            .nav-menu a {
                text-align: center;
                padding: 12px;
                border-bottom: 1px solid #eee;
            }

            .main-content {
                padding: 1rem;
                margin: 1rem;
            }

            .toc-grid {
                grid-template-columns: 1fr;
            }

            .feature-grid {
                grid-template-columns: 1fr;
            }

            .step {
                flex-direction: column;
                text-align: center;
            }

            .step-number {
                margin-right: 0;
                margin-bottom: 0.5rem;
            }
        }

        /* Print Styles */
        @media print {
            .header, .nav-container, .back-to-top, .footer {
                display: none;
            }

            body {
                background: white;
                color: black;
            }

            .main-content {
                box-shadow: none;
                margin: 0;
            }

            .section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <h1>QuickBill 305</h1>
            <p>Comprehensive User Manual - Billing Software for Business Operating Permits and Property Rates</p>
        </div>
    </header>

    <nav class="nav-container">
        <div class="container nav">
            <button class="nav-toggle" onclick="toggleNav()">Menu ☰</button>
            <div class="nav-menu" id="navMenu">
                <a href="#overview">Overview</a>
                <a href="#roles">User Roles</a>
                <a href="#getting-started">Getting Started</a>
                <a href="#business">Business Mgmt</a>
                <a href="#property">Property Mgmt</a>
                <a href="#billing">Billing</a>
                <a href="#payments">Payments</a>
                <a href="#zones">Zones</a>
                <a href="#reports">Reports</a>
                <a href="#settings">Settings</a>
                <a href="#troubleshooting">Support</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="main-content">
            <!-- Table of Contents -->
            <div class="toc">
                <h2>Table of Contents</h2>
                <div class="toc-grid">
                    <div class="toc-section">
                        <h3>Getting Started</h3>
                        <ul>
                            <li><a href="#overview">System Overview</a></li>
                            <li><a href="#roles">User Roles & Permissions</a></li>
                            <li><a href="#getting-started">First Login Process</a></li>
                            <li><a href="#navigation">Dashboard Navigation</a></li>
                        </ul>
                    </div>
                    <div class="toc-section">
                        <h3>Core Features</h3>
                        <ul>
                            <li><a href="#business">Business Management</a></li>
                            <li><a href="#property">Property Management</a></li>
                            <li><a href="#billing">Billing System</a></li>
                            <li><a href="#payments">Payment Processing</a></li>
                        </ul>
                    </div>
                    <div class="toc-section">
                        <h3>Administration</h3>
                        <ul>
                            <li><a href="#zones">Zone Management</a></li>
                            <li><a href="#fee-structure">Fee Structures</a></li>
                            <li><a href="#reports">Reports & Analytics</a></li>
                            <li><a href="#notifications">Notifications</a></li>
                        </ul>
                    </div>
                    <div class="toc-section">
                        <h3>Advanced Topics</h3>
                        <ul>
                            <li><a href="#settings">System Settings</a></li>
                            <li><a href="#public-portal">Public Portal</a></li>
                            <li><a href="#troubleshooting">Troubleshooting</a></li>
                            <li><a href="#best-practices">Best Practices</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- System Overview Section -->
            <section id="overview" class="section">
                <h2>System Overview</h2>
                <p>QuickBill 305 is a comprehensive billing software designed to manage and automate billing processes for business operating permits and property rates. The system provides a complete solution for local assemblies to manage their revenue collection efficiently.</p>
                
                <h3>Key Features</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Multi-User Role System</h4>
                        <p>Five distinct user roles (Super Admin, Admin, Officer, Revenue Officer, Data Collector) each with specific permissions and access levels.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Automated Billing</h4>
                        <p>Annual bill generation with customizable schedules, automatic calculation based on fee structures, and bulk processing capabilities.</p>
                    </div>
                    <div class="feature-card">
                        <h4>GPS Integration</h4>
                        <p>Precise location capture using Google Maps API for accurate business and property location tracking.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Payment Processing</h4>
                        <p>Multiple payment methods including mobile money integration via PayStack, cash payments, and online transactions.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Public Portal</h4>
                        <p>Online payment gateway for citizens to search bills, make payments, and download receipts instantly.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Comprehensive Reporting</h4>
                        <p>Detailed analytics, audit trails, revenue reports, and defaulter management with export capabilities.</p>
                    </div>
                </div>
            </section>

            <!-- User Roles Section -->
            <section id="roles" class="section">
                <h2>User Roles and Permissions</h2>
                
                <h3>Super Admin</h3>
                <div class="alert alert-info">
                    <strong>Full system access with restriction controls</strong><br>
                    The Super Admin has complete control over system restrictions and can override lockouts.
                </div>
                <ul>
                    <li>Set system restriction dates (1-3 months)</li>
                    <li>Configure warning alerts and countdown notifications</li>
                    <li>Manage all system settings and configurations</li>
                    <li>Override system restrictions and unlock operations</li>
                    <li>Access all features available to other roles</li>
                </ul>

                <h3>Admin</h3>
                <div class="alert alert-success">
                    <strong>Full system access excluding restriction controls</strong><br>
                    Administrators manage day-to-day operations and system configuration.
                </div>
                <ul>
                    <li>Add and manage businesses and properties</li>
                    <li>Record payments and generate bills</li>
                    <li>Manage zones, sub-zones, and fee structures</li>
                    <li>Perform bulk operations and adjustments</li>
                    <li>Generate comprehensive reports and analytics</li>
                    <li>Send notifications and manage user accounts</li>
                    <li>Perform system backups and maintenance</li>
                </ul>

                <h3>Officer</h3>
                <div class="alert alert-info">
                    <strong>Operational user with core business functions</strong><br>
                    Officers handle front-line operations and customer interactions.
                </div>
                <ul>
                    <li>Register new businesses and properties</li>
                    <li>Record payment transactions</li>
                    <li>View and edit business/property profiles</li>
                    <li>Generate and print individual bills</li>
                    <li>View map locations and GPS coordinates</li>
                    <li>Access limited reporting functions</li>
                </ul>

                <h3>Revenue Officer</h3>
                <div class="alert alert-warning">
                    <strong>Payment-focused role with location tracking</strong><br>
                    Revenue Officers specialize in payment collection and field operations.
                </div>
                <ul>
                    <li>Record payment transactions</li>
                    <li>Search and view account information</li>
                    <li>Access business and property maps</li>
                    <li>Generate daily collection summaries</li>
                    <li>View payment history and receipts</li>
                </ul>

                <h3>Data Collector</h3>
                <div class="alert alert-info">
                    <strong>Data entry specialist with profile management</strong><br>
                    Data Collectors focus on accurate data capture and profile maintenance.
                </div>
                <ul>
                    <li>Register new businesses and properties</li>
                    <li>Edit existing profiles (limited permissions)</li>
                    <li>View business and property details</li>
                    <li>Access location maps for verification</li>
                    <li>Perform basic data entry functions</li>
                </ul>
            </section>

            <!-- Getting Started Section -->
            <section id="getting-started" class="section">
                <h2>Getting Started</h2>
                
                <h3>First Login Process</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Initial Login</h5>
                            <p>Use your provided username and default password to access the system for the first time.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Password Reset</h5>
                            <p>System prompts for mandatory password change. Choose a strong password meeting security requirements.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Dashboard Access</h5>
                            <p>After password reset, you'll be redirected to your role-specific dashboard.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Profile Setup</h5>
                            <p>Complete your user profile information if required by your administrator.</p>
                        </div>
                    </div>
                </div>

                <h3 id="navigation">Dashboard Navigation</h3>
                <p>Each user role has a customized dashboard and navigation menu designed for their specific responsibilities:</p>
                
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Header Section</h4>
                        <ul>
                            <li>Assembly name and logo</li>
                            <li>User information display</li>
                            <li>Logout and profile options</li>
                            <li>System notifications</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Sidebar Navigation</h4>
                        <ul>
                            <li>Role-specific menu items</li>
                            <li>Quick action buttons</li>
                            <li>Recent activities</li>
                            <li>Shortcut links</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Main Dashboard</h4>
                        <ul>
                            <li>Key performance indicators</li>
                            <li>Statistics and summaries</li>
                            <li>Recent transactions</li>
                            <li>Task reminders</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Footer Information</h4>
                        <ul>
                            <li>System version details</li>
                            <li>Support contact information</li>
                            <li>Documentation links</li>
                            <li>Legal notices</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Business Management Section -->
            <section id="business" class="section">
                <h2>Business Management</h2>
                
                <h3>Business Registration Process</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Navigate to Add Business</h5>
                            <p>Go to Businesses → Add New from your dashboard menu.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Basic Information Entry</h5>
                            <p>Enter business name, owner name, and contact telephone number (all required fields).</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Business Classification</h5>
                            <p>Select business type using typeahead search. Category auto-populates and current bill is calculated automatically.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Location Capture</h5>
                            <p>Click "Capture Location" button, allow GPS access, and verify location accuracy on the map.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">5</div>
                        <div class="step-content">
                            <h5>Zone Assignment</h5>
                            <p>Select appropriate zone and sub-zone from dropdown menus.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">6</div>
                        <div class="step-content">
                            <h5>Financial Information</h5>
                            <p>Enter old bill amount and previous payments. System automatically calculates arrears and total payable.</p>
                        </div>
                    </div>
                </div>

                <h3>Account Number System</h3>
                <div class="alert alert-info">
                    <strong>Automatic Generation:</strong> Account numbers are automatically generated in the format BIZ000001 and cannot be modified once assigned.
                </div>

                <h3>Business Profile Management</h3>
                <h4>Profile Information Display</h4>
                <ul>
                    <li>Complete business information with contact details</li>
                    <li>Interactive map showing exact GPS location</li>
                    <li>Complete billing and payment history</li>
                    <li>Current payment status and outstanding amounts</li>
                    <li>Zone and sub-zone assignments</li>
                </ul>

                <h4>Search and Filter Options</h4>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Search Criteria</h4>
                        <ul>
                            <li>Business name</li>
                            <li>Owner name</li>
                            <li>Account number</li>
                            <li>Phone number</li>
                            <li>Business type</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Filter Options</h4>
                        <ul>
                            <li>Zone and sub-zone</li>
                            <li>Business category</li>
                            <li>Payment status</li>
                            <li>Date ranges</li>
                            <li>Amount ranges</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Property Management Section -->
            <section id="property" class="section">
                <h2>Property Management</h2>
                
                <h3>Property Registration Process</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Owner Information</h5>
                            <p>Enter owner name, contact details, gender, and ownership type (Self/Family/Corporate/Others).</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Property Details</h5>
                            <p>Specify structure type, property type (Modern/Traditional), property use (Commercial/Residential), and number of rooms.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Location and Zone</h5>
                            <p>Capture GPS location and assign to appropriate zone and sub-zone.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Financial Setup</h5>
                            <p>Enter historical bill and payment data. Current bill calculates automatically based on structure, use, and room count.</p>
                        </div>
                    </div>
                </div>

                <h3>Property Number System</h3>
                <div class="alert alert-info">
                    <strong>Format:</strong> Property numbers follow the format PROP000001 and are automatically generated for unique identification.
                </div>

                <h3>Dynamic Billing Calculation</h3>
                <p>The system automatically calculates property bills based on:</p>
                <ul>
                    <li><strong>Structure Type:</strong> Modern Building, Concrete Block, Mud Block, etc.</li>
                    <li><strong>Property Use:</strong> Commercial properties typically have higher rates than residential</li>
                    <li><strong>Room Count:</strong> Fee per room multiplied by total number of rooms</li>
                    <li><strong>Zone Factors:</strong> Different zones may have varying rate structures</li>
                </ul>

                <div class="example">
                    <strong>Example Calculation:</strong><br>
                    Modern Building + Commercial Use + 5 Rooms<br>
                    GHS 150.00 per room × 5 rooms = GHS 750.00 current bill
                </div>
            </section>

            <!-- Billing System Section -->
            <section id="billing" class="section">
                <h2>Billing System</h2>
                
                <h3>Annual Bill Generation</h3>
                <div class="alert alert-warning">
                    <strong>Automated Schedule:</strong> Bills are automatically generated on November 1st annually. Manual generation is available for administrators.
                </div>

                <h4>Bill Generation Process</h4>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Navigate to Bill Generation</h5>
                            <p>Admin → Billing → Generate Bills</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Select Generation Options</h5>
                            <p>Choose generation type (All/Businesses/Properties), billing year, and any filter criteria.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Review and Execute</h5>
                            <p>Verify settings, execute generation, and monitor progress with completion notifications.</p>
                        </div>
                    </div>
                </div>

                <h3>Bill Components</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Business Bill Information</h4>
                        <ul>
                            <li>Business name and owner</li>
                            <li>Business type and category</li>
                            <li>Exact location details</li>
                            <li>Account number and zone</li>
                            <li>Financial breakdown with QR code</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Property Bill Information</h4>
                        <ul>
                            <li>Owner name and contact</li>
                            <li>Property location and details</li>
                            <li>Structure and usage information</li>
                            <li>Property number and zone</li>
                            <li>Financial summary with QR code</li>
                        </ul>
                    </div>
                </div>

                <h3>Bill Status Management</h3>
                <ul>
                    <li><strong>Pending:</strong> Newly generated bills awaiting delivery</li>
                    <li><strong>Served:</strong> Bills delivered to account holders</li>
                    <li><strong>Partially Paid:</strong> Some payment received against bill</li>
                    <li><strong>Paid:</strong> Bill fully settled</li>
                    <li><strong>Overdue:</strong> Bills past their due date</li>
                </ul>

                <h3>Bill Adjustments (Admin Only)</h3>
                <h4>Single Bill Adjustments</h4>
                <p>Make individual adjustments directly from business or property profiles using fixed amounts or percentage reductions.</p>

                <h4>Bulk Adjustments</h4>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Define Criteria</h5>
                            <p>Set filters by business type, zone, sub-zone, or date ranges.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Select Adjustment Method</h5>
                            <p>Choose between fixed amount or percentage adjustment.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Review and Apply</h5>
                            <p>Review affected accounts and execute bulk adjustment with logging.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Payment Processing Section -->
            <section id="payments" class="section">
                <h2>Payment Processing</h2>
                
                <h3>Recording Payments</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Search Account</h5>
                            <p>Enter account number, business name, or owner name to locate the account.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Enter Payment Details</h5>
                            <p>Input payment amount, select payment method, and enter transaction reference if applicable.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Process Payment</h5>
                            <p>Verify details, process payment, and generate receipt automatically.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Receipt and Notification</h5>
                            <p>Print receipt, send SMS notification, and update account balance.</p>
                        </div>
                    </div>
                </div>

                <h3>Payment Methods</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Mobile Money</h4>
                        <p>Integrated with PayStack for MTN, Telecel, and AirtelTigo mobile money payments with real-time verification.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Cash Payments</h4>
                        <p>Manual receipt generation with cash float management and daily reconciliation support.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Bank Transfers</h4>
                        <p>Record bank transfer payments with reference number tracking and verification.</p>
                    </div>
                    <div class="feature-card">
                        <h4>Online Payments</h4>
                        <p>PayStack integration for online card payments through the public portal.</p>
                    </div>
                </div>

                <h3>Payment Tracking</h3>
                <ul>
                    <li>Unique payment reference numbers for all transactions</li>
                    <li>Complete transaction history with timestamps</li>
                    <li>Payment method and processing officer records</li>
                    <li>Receipt generation and SMS notification logs</li>
                    <li>Account balance tracking and updates</li>
                </ul>
            </section>

            <!-- Zone Management Section -->
            <section id="zones" class="section">
                <h2>Zone Management</h2>
                
                <h3>Zone Creation and Setup</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Create New Zone</h5>
                            <p>Navigate to Admin → Zones → Add New Zone and enter zone name, code, and description.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Add Sub-Zones</h5>
                            <p>Create sub-zones within each zone for more detailed geographical organization.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Assign Properties and Businesses</h5>
                            <p>Link businesses and properties to appropriate zones during registration.</p>
                        </div>
                    </div>
                </div>

                <h3>Zone-Based Operations</h3>
                <ul>
                    <li>Filter businesses and properties by zone</li>
                    <li>Generate zone-specific reports and analytics</li>
                    <li>Assign collection responsibilities to officers</li>
                    <li>Track performance metrics by zone</li>
                    <li>Manage zone boundaries and descriptions</li>
                </ul>

                <div class="alert alert-info">
                    <strong>Zone Codes:</strong> Each zone must have a unique code for identification. Sub-zones inherit the parent zone relationship.
                </div>
            </section>

            <!-- Fee Structure Section -->
            <section id="fee-structure" class="section">
                <h2>Fee Structure Management</h2>
                
                <h3>Business Fee Configuration</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Navigate to Fee Structure</h5>
                            <p>Admin → Fee Structure → Business Fees</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Add Business Type</h5>
                            <p>Enter business type name and define associated categories with their respective fees.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Set Fee Amounts</h5>
                            <p>Define fee amounts for each category within the business type.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Activate Fee Structure</h5>
                            <p>Enable the fee structure for use in bill calculations.</p>
                        </div>
                    </div>
                </div>

                <h3>Property Fee Configuration</h3>
                <p>Property fees are calculated based on structure type, property use, and number of rooms:</p>
                
                <div class="example">
                    <strong>Property Fee Examples:</strong><br>
                    Modern Building - Residential: GHS 75.00 per room<br>
                    Modern Building - Commercial: GHS 150.00 per room<br>
                    Concrete Block - Residential: GHS 50.00 per room<br>
                    Concrete Block - Commercial: GHS 100.00 per room
                </div>

                <h3>Dynamic Fee Calculation</h3>
                <ul>
                    <li>Typeahead search for business types during registration</li>
                    <li>Auto-population of categories based on selected business type</li>
                    <li>Real-time fee calculation and bill amount preview</li>
                    <li>Automatic property bill calculation using room count multiplication</li>
                </ul>
            </section>

            <!-- Reports Section -->
            <section id="reports" class="section">
                <h2>Reports and Analytics</h2>
                
                <h3>Revenue Reports</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Revenue Analysis</h4>
                        <ul>
                            <li>Total collections by period</li>
                            <li>Payment method breakdown</li>
                            <li>Zone-wise revenue analysis</li>
                            <li>Collection trends and patterns</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Collection Performance</h4>
                        <ul>
                            <li>Daily collection summaries</li>
                            <li>Officer performance metrics</li>
                            <li>Target vs achievement analysis</li>
                            <li>Collection efficiency rates</li>
                        </ul>
                    </div>
                </div>

                <h3>Defaulter Management</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Generate Defaulter Report</h5>
                            <p>Navigate to Admin → Reports → Defaulters Report</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Apply Filters</h5>
                            <p>Filter by zone, amount range, account type, and time period.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Review and Export</h5>
                            <p>Review defaulter list and export for communication or collection activities.</p>
                        </div>
                    </div>
                </div>

                <h3>Audit and Activity Reports</h3>
                <ul>
                    <li><strong>User Activity Tracking:</strong> Login/logout logs, transaction records, data modifications</li>
                    <li><strong>System Audit Reports:</strong> Bill generation logs, payment processing records, fee changes</li>
                    <li><strong>Financial Reconciliation:</strong> Daily cash reconciliation, payment summaries, balance reports</li>
                </ul>
            </section>

            <!-- Notifications Section -->
            <section id="notifications" class="section">
                <h2>Notification System</h2>
                
                <h3>SMS Notifications</h3>
                <h4>Twilio Integration Setup</h4>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Configure Twilio Settings</h5>
                            <p>Enter Account SID, Auth Token, and phone number in Admin → Settings → API Settings</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Test Connection</h5>
                            <p>Verify connectivity and send test messages to ensure proper configuration.</p>
                        </div>
                    </div>
                </div>

                <h3>Message Templates</h3>
                <ul>
                    <li><strong>Account Creation:</strong> Welcome messages with account number and current bill</li>
                    <li><strong>Payment Confirmation:</strong> Payment receipts with balance information</li>
                    <li><strong>Bill Reminders:</strong> Outstanding balance notifications</li>
                    <li><strong>System Alerts:</strong> Important system notifications and updates</li>
                </ul>

                <h3>Bulk Notification Process</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Select Recipients</h5>
                            <p>Filter by defaulter status, zone, account type, or create custom lists.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Compose Message</h5>
                            <p>Select template or create custom message with variable replacements.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Send and Track</h5>
                            <p>Schedule delivery, track status, and handle failed deliveries.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Settings Section -->
            <section id="settings" class="section">
                <h2>System Settings</h2>
                
                <h3>Assembly Configuration</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Basic Settings</h4>
                        <ul>
                            <li>Assembly name (appears on all bills)</li>
                            <li>Contact information</li>
                            <li>Physical address</li>
                            <li>Official logo upload</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Branding Options</h4>
                        <ul>
                            <li>Logo positioning on bills</li>
                            <li>Color scheme selection</li>
                            <li>Header/footer customization</li>
                            <li>Official seal placement</li>
                        </ul>
                    </div>
                </div>

                <h3>System Restrictions (Super Admin Only)</h3>
                <div class="alert alert-warning">
                    <strong>Critical Feature:</strong> System restrictions can lock out all users except Super Admins. Use carefully.
                </div>

                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Set Restriction Parameters</h5>
                            <p>Define start date, restriction period (1-3 months), and warning notification days.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Configure Warnings</h5>
                            <p>Set up countdown notifications and escalation procedures.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Monitor and Manage</h5>
                            <p>Track restriction status and provide unlock procedures when needed.</p>
                        </div>
                    </div>
                </div>

                <h3>Backup and Maintenance</h3>
                <h4>Automated Backup System</h4>
                <ul>
                    <li>Daily backup scheduling with verification</li>
                    <li>Incremental backup options for efficiency</li>
                    <li>Cloud storage integration capabilities</li>
                    <li>Backup history tracking and management</li>
                </ul>

                <h4>Manual Backup Process</h4>
                <p>Navigate to Admin → Settings → Backup and choose from full database backup, selective table backup, file system backup, or configuration backup options.</p>
            </section>

            <!-- Public Portal Section -->
            <section id="public-portal" class="section">
                <h2>Public Portal</h2>
                
                <h3>Citizen Self-Service Features</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Bill Search and Viewing</h4>
                        <ul>
                            <li>Search by account number or business name</li>
                            <li>View complete bill details</li>
                            <li>Check payment history</li>
                            <li>Review outstanding balances</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Online Payment Processing</h4>
                        <ul>
                            <li>Multiple payment method options</li>
                            <li>PayStack payment gateway integration</li>
                            <li>Real-time transaction processing</li>
                            <li>Instant receipt generation</li>
                        </ul>
                    </div>
                </div>

                <h3>Payment Process for Citizens</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h5>Access Public Portal</h5>
                            <p>Visit the assembly's public portal website.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h5>Search Account</h5>
                            <p>Enter account number or business name to locate account.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h5>Review Bill</h5>
                            <p>Verify bill details and outstanding amount.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h5>Make Payment</h5>
                            <p>Choose payment method and complete transaction.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">5</div>
                        <div class="step-content">
                            <h5>Get Receipt</h5>
                            <p>Download digital receipt and receive SMS confirmation.</p>
                        </div>
                    </div>
                </div>

                <h3>Payment Verification</h3>
                <ul>
                    <li>Transaction reference lookup system</li>
                    <li>Real-time payment status checking</li>
                    <li>Receipt reprinting capabilities</li>
                    <li>Dispute resolution contact information</li>
                </ul>
            </section>

            <!-- Troubleshooting Section -->
            <section id="troubleshooting" class="section">
                <h2>Troubleshooting</h2>
                
                <h3>Common Login Issues</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Cannot Login</h4>
                        <ul>
                            <li>Verify username and password accuracy</li>
                            <li>Check for caps lock activation</li>
                            <li>Contact administrator for password reset</li>
                            <li>Clear browser cache and cookies</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>First Login Password Change Fails</h4>
                        <ul>
                            <li>Ensure new password meets requirements</li>
                            <li>Confirm password confirmation matches</li>
                            <li>Try different browser</li>
                            <li>Contact system administrator</li>
                        </ul>
                    </div>
                </div>

                <h3>Bill Generation Problems</h3>
                <div class="alert alert-warning">
                    <strong>Common Issue:</strong> Bills not generating for some accounts
                </div>
                <p><strong>Solutions:</strong></p>
                <ul>
                    <li>Verify account status is active</li>
                    <li>Check fee structure configuration</li>
                    <li>Ensure zone assignments are complete</li>
                    <li>Review audit logs for error details</li>
                </ul>

                <h3>Payment Processing Issues</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Mobile Money Payments Failing</h4>
                        <ul>
                            <li>Verify PayStack configuration</li>
                            <li>Check network connectivity</li>
                            <li>Confirm mobile money account balance</li>
                            <li>Try alternative payment method</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Receipt Generation Fails</h4>
                        <ul>
                            <li>Check printer connectivity</li>
                            <li>Verify receipt template configuration</li>
                            <li>Ensure sufficient paper supply</li>
                            <li>Restart printing service</li>
                        </ul>
                    </div>
                </div>

                <h3>Getting Support</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Internal Support</h4>
                        <ul>
                            <li>System Administrator contact</li>
                            <li>User manual reference</li>
                            <li>Internal training resources</li>
                            <li>Peer user consultation</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>External Support</h4>
                        <ul>
                            <li>Developer support contact</li>
                            <li>System documentation portal</li>
                            <li>Online help resources</li>
                            <li>Professional training options</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Best Practices Section -->
            <section id="best-practices" class="section">
                <h2>Best Practices</h2>
                
                <h3>Data Entry Standards</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Business Registration</h4>
                        <ul>
                            <li>Use consistent naming conventions</li>
                            <li>Verify location accuracy with GPS</li>
                            <li>Complete all required fields</li>
                            <li>Double-check zone assignments</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>Property Registration</h4>
                        <ul>
                            <li>Accurate room count is essential</li>
                            <li>Verify owner contact information</li>
                            <li>Ensure proper structure classification</li>
                            <li>Confirm property use designation</li>
                        </ul>
                    </div>
                </div>

                <h3>Security Guidelines</h3>
                <div class="alert alert-success">
                    <strong>Security Best Practices</strong><br>
                    Following these guidelines ensures system security and data protection.
                </div>
                <ul>
                    <li><strong>Password Management:</strong> Change default passwords immediately, use strong requirements, update regularly</li>
                    <li><strong>Data Protection:</strong> Regular system backups, secure payment processing, confidential information handling</li>
                    <li><strong>Access Control:</strong> Monitor user activity logs, review permissions regularly, audit system access</li>
                    <li><strong>Transaction Security:</strong> Verify payment details, maintain audit trails, secure receipt storage</li>
                </ul>

                <h3>System Performance Optimization</h3>
                <div class="feature-grid">
                    <div class="feature-card">
                        <h4>Maintenance Tasks</h4>
                        <ul>
                            <li>Regular database optimization</li>
                            <li>Monitor system resources</li>
                            <li>Archive old data periodically</li>
                            <li>Keep software updated</li>
                        </ul>
                    </div>
                    <div class="feature-card">
                        <h4>User Training</h4>
                        <ul>
                            <li>Regular training sessions</li>
                            <li>Documentation updates</li>
                            <li>Best practice sharing</li>
                            <li>Performance monitoring</li>
                        </ul>
                    </div>
                </div>

                <h3>Daily Operations Checklist</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">✓</div>
                        <div class="step-content">
                            <h5>Morning Setup</h5>
                            <p>Verify system backups completed, check payment synchronization, review error logs</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">✓</div>
                        <div class="step-content">
                            <h5>Daily Operations</h5>
                            <p>Process payments accurately, maintain data quality, respond to citizen inquiries</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">✓</div>
                        <div class="step-content">
                            <h5>End of Day</h5>
                            <p>Generate daily reports, reconcile cash payments, secure system access</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 QuickBill 305. All rights reserved. | Version 1.0 | For support, contact your system administrator.</p>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <a href="#top" class="back-to-top" id="backToTop">↑</a>

    <script>
        // Navigation toggle for mobile
        function toggleNav() {
            const navMenu = document.getElementById('navMenu');
            navMenu.classList.toggle('active');
        }

        // Back to top functionality
        window.addEventListener('scroll', function() {
            const backToTop = document.getElementById('backToTop');
            if (window.scrollY > 300) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Close mobile menu when clicking on a link
        document.querySelectorAll('.nav-menu a').forEach(link => {
            link.addEventListener('click', function() {
                document.getElementById('navMenu').classList.remove('active');
            });
        });

        // Print functionality
        function printPage() {
            window.print();
        }

        // Add keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('navMenu').classList.remove('active');
            }
        });
    </script>
</body>
</html>