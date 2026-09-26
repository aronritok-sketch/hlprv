"""Szerepkör → jogosultság. A WordPress bővítmény ugyanezt a táblát kapja meg a /me végponttól."""

ALL = "*"

CAPS: dict[str, set[str]] = {
    "admin": {ALL},
    "seo_manager": {
        "project.view", "project.create", "project.edit", "project.transition",
        "research.view", "research.edit",
        "keywords.view", "keywords.edit",
        "structure.view", "structure.edit",
        "strategy.view", "strategy.edit", "strategy.status",
        "wireframes.view", "wireframes.edit",
        "documents.view", "documents.generate", "documents.generate.writer",
        "tasks.view", "tasks.edit", "tasks.update",
        "approve.internal", "approve.request_client",
        "ai.run", "integrations.run", "audit.view", "audit.edit",
        "team.view", "comments.write", "mail.use",
    },
    "content_manager": {
        "project.view",
        "research.view", "keywords.view", "structure.view",
        "strategy.view", "strategy.status",
        "wireframes.view", "wireframes.edit",
        "documents.view", "documents.generate.writer",
        "tasks.view", "tasks.update",
        "team.view", "comments.write", "mail.use",
    },
    "designer": {
        "project.view", "structure.view", "wireframes.view",
        "documents.view", "tasks.view", "tasks.update", "comments.write", "mail.use",
    },
    "developer": {
        "project.view", "structure.view", "wireframes.view",
        "documents.view", "tasks.view", "tasks.update", "audit.view", "comments.write", "mail.use",
    },
    "staff": {"mail.use"},
}

# Melyik szerepkör milyen dokumentumtípust láthat (a "documents.view" mellett).
DOC_VISIBILITY: dict[str, set[str] | None] = {
    "admin": None,  # mindent
    "seo_manager": None,
    "content_manager": {
        "content_strategy", "roadmap", "wireframe_deck", "keyword_research", "structure",
        "writer_brief", "designer_brief", "seo_checklist", "seo_strategy", "dev_brief",
    },
    "designer": {"designer_brief", "wireframe_deck", "structure"},
    "developer": {"dev_brief", "seo_checklist", "structure", "tech_audit"},
}

# Gyártási feladatok: ki melyik szerepkör feladatait frissítheti.
TASK_ROLES: dict[str, set[str] | None] = {
    "admin": None,
    "seo_manager": None,
    "content_manager": {"writer", "seo"},
    "designer": {"designer"},
    "developer": {"developer"},
}


def has(role: str, cap: str) -> bool:
    caps = CAPS.get(role, set())
    return ALL in caps or cap in caps


def caps_for(role: str) -> list[str]:
    caps = CAPS.get(role, set())
    if ALL in caps:
        return sorted(set().union(*[c for r, c in CAPS.items() if r != "admin"]) | {"settings.manage"})
    return sorted(caps)


def can_view_doc(role: str, doc_type: str) -> bool:
    allowed = DOC_VISIBILITY.get(role, set())
    return allowed is None or doc_type in allowed


def can_update_task(role: str, task_role: str) -> bool:
    allowed = TASK_ROLES.get(role, set())
    return allowed is None or task_role in allowed
