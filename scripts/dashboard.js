document.addEventListener("DOMContentLoaded", function () {
  function updateAiRecommendationNumbers() {
    document.querySelectorAll(".ai-recommendation-item").forEach(function (item, index) {
      const numberSpan = item.querySelector(".ai-recommendation-header h3 > span");
      if (numberSpan) {
        numberSpan.textContent = `${index + 1}. `;
      }
    });
  }

  document.querySelectorAll(".ai-recommendation-item").forEach(function (item) {
    item.addEventListener("click", function () {
      item.classList.toggle("expanded");
    });
  });

  document.querySelectorAll(".delete-ai-recommendation").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const item = el.closest(".ai-recommendation-item");
      const id = item.getAttribute("data-id");

      fetch("endpoints/ai/delete_recommendation.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": window.csrfToken,
        },
        body: JSON.stringify({ id: id }),
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            item.remove();
            updateAiRecommendationNumbers();
            showSuccessMessage(translate("success"));
          } else {
            showErrorMessage(data.message || translate("failed_delete_ai_recommendation"));
          }
        })
        .catch(error => {
          console.error(error);
          showErrorMessage(translate("unknown_error"));
        });
    });
  });

});


// "By category" card: Monthly / This pay period. The choice is remembered
// per browser.
document.addEventListener("DOMContentLoaded", function () {
  const card = document.getElementById("category-card");
  if (!card) {
    return;
  }
  const storageKey = "tally.categoryCardView";

  function show(view) {
    card.querySelectorAll("[data-category-view]").forEach(function (button) {
      const selected = button.dataset.categoryView === view;
      button.classList.toggle("is-selected", selected);
      button.setAttribute("aria-pressed", selected ? "true" : "false");
    });
    card.querySelectorAll("[data-category-panel]").forEach(function (panel) {
      panel.classList.toggle("hide", panel.dataset.categoryPanel !== view);
    });
  }

  card.querySelectorAll("[data-category-view]").forEach(function (button) {
    button.addEventListener("click", function () {
      show(button.dataset.categoryView);
      try {
        localStorage.setItem(storageKey, button.dataset.categoryView);
      } catch (e) {
        // Storage unavailable: the choice just isn't remembered.
      }
    });
  });

  try {
    const saved = localStorage.getItem(storageKey);
    if (saved === "period" || saved === "monthly") {
      show(saved);
    }
  } catch (e) {
    // Storage unavailable: keep the monthly view.
  }
});
