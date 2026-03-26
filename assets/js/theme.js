document.addEventListener("DOMContentLoaded", function () {
    const themeToggle = document.getElementById("themeToggle");
    const savedTheme = localStorage.getItem("theme");
    const sidebar = document.querySelector(".sidebar");
    const topbar = document.querySelector(".topbar");

    if (savedTheme === "dark") {
        document.body.classList.add("dark");
        if (themeToggle) themeToggle.checked = true;
    }

    if (themeToggle) {
        themeToggle.addEventListener("change", function () {
            if (this.checked) {
                document.body.classList.add("dark");
                localStorage.setItem("theme", "dark");
            } else {
                document.body.classList.remove("dark");
                localStorage.setItem("theme", "light");
            }
        });
    }

    if (sidebar && topbar) {
        const toggleButton = document.createElement("button");
        const sidebarId = sidebar.id || "mobileSidebar";

        sidebar.id = sidebarId;
        document.body.classList.add("has-mobile-sidebar");

        toggleButton.type = "button";
        toggleButton.className = "mobile-sidebar-toggle";
        toggleButton.setAttribute("aria-controls", sidebarId);
        toggleButton.setAttribute("aria-label", "إظهار أو إخفاء لوحة التحكم");

        const updateSidebarState = function (isOpen) {
            document.body.classList.toggle("sidebar-open", isOpen);
            toggleButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
            toggleButton.innerHTML = isOpen
                ? '<span class="mobile-sidebar-toggle-icon">✖</span><span>إخفاء لوحة التحكم</span>'
                : '<span class="mobile-sidebar-toggle-icon">☰</span><span>إظهار لوحة التحكم</span>';
        };

        topbar.insertBefore(toggleButton, topbar.firstChild);

        let isDesktopLayout = window.innerWidth > 900;

        toggleButton.addEventListener("click", function () {
            updateSidebarState(!document.body.classList.contains("sidebar-open"));
        });

        updateSidebarState(isDesktopLayout);
        window.addEventListener("resize", function () {
            const shouldUseDesktopLayout = window.innerWidth > 900;

            if (shouldUseDesktopLayout !== isDesktopLayout) {
                isDesktopLayout = shouldUseDesktopLayout;
                updateSidebarState(shouldUseDesktopLayout);
            }
        });
    }
});
