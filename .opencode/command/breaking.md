---
description: Breaking change - commit, update CHANGELOG, README, CLAUDE.md
model: anthropic/claude-sonnet-4-20250514
---

# Breaking Change Release

Ban dang thuc hien mot breaking change release. Lam theo cac buoc sau:

## 1. Phan tich thay doi

Xem git diff va staged changes:
!`git diff --cached --stat`
!`git diff --stat`
!`git log --oneline -5`

## 2. Xac dinh pham vi breaking change

Voi argument duoc cung cap: **$ARGUMENTS**

Neu khong co argument, hoi user:
- Ten tinh nang/thay doi chinh
- Ly do day la breaking change (API thay doi, schema thay doi, etc.)
- Version moi (neu co)

## 3. Cap nhat CHANGELOG.md

File hien tai: @CHANGELOG.md

Them entry moi vao section `[Unreleased]` hoac tao version moi:

Format:
```markdown
## [X.Y.Z] - YYYY-MM-DD

### BREAKING CHANGES
- Mo ta breaking change va migration guide neu can

### Tinh nang moi
- ...

### Cai thien
- ...

### Sua loi
- ...
```

## 4. Cap nhat README.md neu can

File hien tai: @README.md

Chi update neu breaking change anh huong den:
- Cach cai dat
- Cau truc du an
- Tech stack
- Tinh nang chinh

## 5. Cap nhat CLAUDE.md neu can

File: `C:\Users\ADMIN\.claude\CLAUDE.md`

Chi update neu breaking change anh huong den:
- Tech stack defaults
- Repository conventions
- Working style guidelines

## 6. Tao commit

Commit message format cho breaking change:
```
feat!: <summary>

BREAKING CHANGE: <description>

- Detail 1
- Detail 2
```

Hoac voi scope:
```
feat(api)!: <summary>

BREAKING CHANGE: <description>
```

## 7. Checklist truoc khi commit

- [ ] CHANGELOG.md da duoc cap nhat
- [ ] README.md da duoc cap nhat (neu can)
- [ ] CLAUDE.md da duoc cap nhat (neu can)
- [ ] Commit message co `!` va `BREAKING CHANGE:`
- [ ] Tat ca files lien quan da duoc staged

## Luu y

- KHONG push tu dong, chi commit
- KHONG tao git tag (de user lam manual)
- Giai thich ro cho user nhung gi da thay doi
- De xuat migration steps neu can
