function toggle(id) {
    const elem = $("#" + id)
    const arrow = $(".arrow.btn-" + id)
    if(arrow.hasClass("down")) {
        elem.css("display", "inline")
        arrow.removeClass("down")
    } else {
        elem.css("display", "none")
        arrow.addClass("down")
    }
}