document.addEventListener("DOMContentLoaded", function () {
    const themeToggle = document.getElementById("themeToggle");
    const savedTheme = localStorage.getItem("theme");
    const sidebar = document.querySelector(".sidebar");
    const topbar = document.querySelector(".topbar");
    const mobileSidebarBreakpoint = 900;
    const showSidebarLabel = "إظهار لوحة التحكم";
    const hideSidebarLabel = "إخفاء لوحة التحكم";

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
        const sidebarId = sidebar.id || "mobile-sidebar";
        const toggleIcon = document.createElement("span");
        const toggleLabel = document.createElement("span");

        sidebar.id = sidebarId;
        document.body.classList.add("has-mobile-sidebar");

        toggleButton.type = "button";
        toggleButton.className = "mobile-sidebar-toggle";
        toggleButton.setAttribute("aria-controls", sidebarId);
        toggleIcon.className = "mobile-sidebar-toggle-icon";
        toggleButton.appendChild(toggleIcon);
        toggleButton.appendChild(toggleLabel);

        const updateSidebarState = function (isOpen) {
            document.body.classList.toggle("sidebar-open", isOpen);
            toggleButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
            toggleButton.setAttribute("aria-label", isOpen ? hideSidebarLabel : showSidebarLabel);
            toggleIcon.textContent = isOpen ? "✖" : "☰";
            toggleLabel.textContent = isOpen ? hideSidebarLabel : showSidebarLabel;
        };

        topbar.insertBefore(toggleButton, topbar.firstChild);

        const mobileSidebarMedia = window.matchMedia("(max-width: " + mobileSidebarBreakpoint + "px)");

        toggleButton.addEventListener("click", function () {
            updateSidebarState(!document.body.classList.contains("sidebar-open"));
        });

        const shouldOpenSidebarOnLoad = !mobileSidebarMedia.matches;
        updateSidebarState(shouldOpenSidebarOnLoad);

        mobileSidebarMedia.addEventListener("change", function (event) {
            const shouldOpenSidebar = !event.matches;
            updateSidebarState(shouldOpenSidebar);
        });
    }
});
