# Agent Notes & Commands

## Release Process

### Creating a GitHub Release

**Command:**
```bash
make release VERSION=x.x.x
```

**What it does:**
1. Creates a git tag `vx.x.x`
2. Builds a release zip file (`meta-offline-conversions.zip`)
3. Pushes the tag to GitHub

**After running:**
- Tag is pushed: `git push origin v{VERSION}` is executed automatically
- ZIP file is created in the project root
- ZIP can be manually uploaded to GitHub Releases if needed

### Alternative: Full Release with Version Bump

**Command:**
```bash
make release-all VERSION=x.x.x MESSAGE="Release vx.x.x"
```

**What it does:**
1. Bumps version in plugin file
2. Updates CHANGELOG.md
3. Commits changes
4. Creates git tag
5. Builds release zip
6. Does NOT push automatically

**Then push with:**
```bash
make release-all-push VERSION=x.x.x MESSAGE="Release vx.x.x"
```

## Version Management

### Manual Version Update
If you've already updated the version in the code and CHANGELOG:
```bash
make release VERSION=x.x.x
```

### Automatic Version Bump
If you need to bump the version number:
```bash
make release-all VERSION=x.x.x MESSAGE="Release vx.x.x"
git push origin main
git push origin vx.x.x
```

## Quick Reference

| Task | Command |
|------|---------|
| Create release with current version | `make release VERSION=x.x.x` |
| Bump version + release | `make release-all VERSION=x.x.x MESSAGE="msg"` |
| Just build ZIP | `make zip` |
| Just create tag | `make tag VERSION=x.x.x` |
| Update CHANGELOG only | `make changelog VERSION=x.x.x` |

## Notes

- Always verify the ZIP file is created: `ls -lh *.zip`
- Tag format is always `vx.x.x` (with 'v' prefix)
- The update checker looks for releases on GitHub automatically
- Plugin update checker requires the tag on GitHub to work
