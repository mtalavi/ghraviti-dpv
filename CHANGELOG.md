# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Nothing yet

### Changed
- Nothing yet

### Fixed
- Nothing yet

---

## [1.0.0] - 2026-01-02

### Added
- Initial release of DPV Hub
- User management with encrypted fields (full_name, email, mobile, emirates_id)
- Admin and Super Admin role system
- Event management and console
- QR code generation for volunteers
- Secure file uploads for avatars and Emirates ID
- Email notification system
- Guest check-in/check-out system

### Security
- AES-256-GCM encryption for sensitive user data
- Blind indexes for searchable encrypted fields
- CSRF protection on all forms
- Rate limiting for sensitive admin actions
- HSTS header enforcement
