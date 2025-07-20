Absolutely! Here's your full, clean, and combined CONTRIBUTING.md file with everything properly formatted in one Markdown file — including all sections you've written and requested, organized for readability and GitHub friendliness.

# Contributing to Squehub

Thank you for considering contributing to **Squehub** — a lightweight PHP framework crafted for speed, simplicity, and modularity. Whether you're fixing bugs, improving documentation, or building new packages, we welcome all contributions!

---

## 📦 About Squehub

Squehub is a modern PHP framework that supports:

- Modular architecture
- Blade-like templating
- Simple, expressive routing
- Built-in ORM support
- Extensibility via Composer packages

---

## 📋 How to Contribute

Follow the steps below to make a contribution:

### 1. Fork the Repository

Click the **Fork** button on the top right of [https://github.com/squehub/squehub](https://github.com/squehub/squehub).

---

### 2. Clone Your Fork

git clone https://github.com/your-username/squehub.git
cd squehub

---

### 3. Create a Branch
Use a descriptive name based on the type of contribution:


# For a feature
git checkout -b feature/my-awesome-feature

# For a bug fix
git checkout -b fix/router-parameter-bug
### 4. Install Dependencies
Make sure Composer is installed, then run:


composer install
### 5. Run the Project Locally
If the project has a built-in development server:


php -S localhost:8000 -t public
Then visit http://localhost:8000 in your browser.

✏️ Making Your Changes
Keep changes focused and meaningful.

Follow the existing file and folder structure.

Use clear naming; add comments where necessary.

If you add a new class, update Composer autoload:


composer dump-autoload
Write or update tests if your change affects core functionality.

Update documentation where necessary.

✅ Code Guidelines
Follow PSR-12 PHP coding standard.

Keep code modular and DRY (Don't Repeat Yourself).

Include PHPDoc comments for public classes and methods.

Avoid unnecessary dependencies.

Use meaningful commit messages, such as:


Fix: Resolve undefined variable in Router
Add: SessionManager to simplify session access
📦 Creating Squehub Packages
Want to extend Squehub through a custom package? Here's how:

Inside the root directory, place your package here:


project/packages/{vendor}/{package-name}
Example:


project/packages/squehub/authenticator
Include a valid composer.json file inside the package directory.

Use a src/ directory for your classes and follow PSR-4 autoloading.

Make sure the package works with Squehub and is testable.

📤 Submitting a Pull Request
Once your changes are ready:

Push your branch:

git push origin feature/my-awesome-feature
Go to your fork on GitHub and click "Compare & pull request".

Fill out the PR details:

What you changed

Why it's needed

Any related issues

Click "Create pull request" — we’ll review it shortly!

🧪 Testing Before Submitting
Before creating a pull request:

Make sure your code runs successfully.

Ensure all relevant features work as expected.

If needed, update tests or add new ones.

Run:

composer dump-autoload
🐛 Reporting Bugs
If you're unable to contribute code but found a bug:

Open a GitHub Issue

Include:

A clear bug description

Steps to reproduce it

Expected and actual behavior

Your environment (PHP version, OS, etc.)

🤝 Community & Conduct
We’re committed to fostering a welcoming and respectful environment for all.

All contributors must follow the Contributor Covenant Code of Conduct.

Toxic or disrespectful behavior is not tolerated.
