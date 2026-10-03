"""Build-only target/ownership validation. No shared PHP runtime."""
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
REGISTRY = 'wordpress/release/plugin-targets.json'


def require(ok, message):
    if not ok:
        raise ValueError(message)


def targets(root=ROOT):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            require(key not in result, 'duplicate/ambiguous target field')
            result[key] = value
        return result
    result = json.loads((root / REGISTRY).read_text(), object_pairs_hook=unique)
    require(set(result) == {'writeleash', 'price-history', 'price-campaigns'}, 'unknown/missing target')
    for key, spec in result.items():
        slug = 'writeleash' if key == 'writeleash' else 'writeleash-' + key
        release = 'wordpress/release' + ('' if key == 'writeleash' else '/' + key)
        expected = {'root': 'wordpress/' + slug, 'main': slug + '.php',
                    'text_domain': slug, 'release': release,
                    'manifest': release + ('/writeleash-distribution-files.txt' if key == 'writeleash' else '/distribution-files.txt'),
                    'assets': 'wordpress/assets' + ('' if key == 'writeleash' else '/' + key),
                    'tests': 'wordpress/tests' + ('' if key == 'writeleash' else '/' + key)}
        require(all(spec.get(k) == v for k, v in expected.items()), 'ambiguous target paths: ' + key)
        require(re.fullmatch(r'\d+\.\d+\.\d+', spec['version']), 'invalid target version')
        if key == 'writeleash':
            require(spec['namespace'] == 'WriteLeash', 'flagship namespace drift')
        if key != 'writeleash':
            prefix = 'writeleash_' + key.replace('-', '_') + '_'
            internal = {'namespace': 'WriteLeash\\' + ''.join(p.title() for p in key.split('-')),
                        'table_prefix': prefix, 'option_prefix': prefix,
                        'scheduler_group': slug, 'action_hook': prefix + 'wakeup',
                        'rest_namespace': slug + '/v1', 'asset_manifest': release + '/assets-files.txt'}
            require(all(spec.get(k) == v for k, v in internal.items()), 'internal ownership drift: ' + key)
    return result


def target(name, root=ROOT):
    registry = targets(root)
    require(name in registry, 'unknown/ambiguous plugin target')
    return registry[name]


def manifest(data, count=None, empty=False):
    lines = data.decode().splitlines()
    require(all(line == line.strip() for line in lines), 'manifest whitespace')
    paths = [line for line in lines if line and not line.startswith('#')]
    require(paths == sorted(set(paths)) and (empty or paths) and
            (count is None or len(paths) == count), 'manifest count/order/duplicates')
    require(all(re.fullmatch(r'[A-Za-z0-9_.\-/]+', p) and not p.startswith('/') and
                '..' not in p and all(part not in ('', '.') for part in p.split('/'))
                for p in paths), 'unsafe manifest')
    return paths


def production_inventory(root=ROOT):
    policy = json.loads((root / '.github/ci/path-ownership.json').read_text())
    inventory = {}
    for group in policy['production_groups']:
        for file in group['files']:
            path = group['prefix'] + file
            require(path not in inventory, 'duplicate production ownership')
            inventory[path] = group['gates']
    actual = {p.relative_to(root).as_posix() for p in root.rglob('*')
              if p.is_file() and p.suffix.lower() == '.php' and not
              p.relative_to(root).as_posix().startswith(('wordpress/tests/', 'wordpress/release/', '.github/ci/', 'tests/', '.git/'))}
    require(actual == set(inventory), 'unknown/missing production PHP ownership')
    return inventory


def tokens(data):
    process = subprocess.run(['php', str(ROOT / 'wordpress/tests/portfolio/php-tokens.php')],
                             input=data, capture_output=True)
    require(process.returncode == 0, 'PHP parse/token audit failed (source suppressed)')
    return json.loads(process.stdout)


def php_ownership(payload, spec):
    """Tokenize declarations/includes; refuse copied code, dynamic/sibling imports."""
    declarations = {}
    includes = {}
    for path, data in payload.items():
        if not path.lower().endswith('.php'):
            continue
        ts = tokens(data)
        texts = [text for _, text in ts]
        code = ''.join(texts)
        namespaces = []
        depth = 0
        class_depth = None
        pending_class = None
        current_class = None
        for i, (kind, text) in enumerate(ts):
            if kind == 'T_NAMESPACE':
                end = texts.index(';', i)
                namespaces.append(''.join(texts[i + 1:end]))
            if kind in ('T_CLASS', 'T_INTERFACE', 'T_TRAIT', 'T_ENUM') and i + 1 < len(ts) and ts[i + 1][0] == 'T_STRING':
                name = ts[i + 1][1]
                pending_class = spec['namespace'] + '\\' + name
                identifier = ('class', pending_class.lower())
                require(identifier not in declarations, 'duplicate class declaration')
                declarations[identifier] = path
            if text == '{':
                depth += 1
                if pending_class:
                    class_depth = depth
                    current_class = pending_class
                    pending_class = None
            if text == '}':
                if depth == class_depth:
                    class_depth = None
                    current_class = None
                depth -= 1
            if kind in ('T_FUNCTION', 'T_CONST') and current_class:
                j = i + 1 + (kind == 'T_FUNCTION' and texts[i + 1] == '&')
                if ts[j][0] == 'T_STRING':
                    identifier = ('member', current_class.lower() + '::' + (texts[j].lower() if kind == 'T_FUNCTION' else texts[j]))
                    require(identifier not in declarations, 'duplicate class method/constant')
                    declarations[identifier] = path
            if kind == 'T_CONSTANT_ENCAPSED_STRING' and spec['text_domain'] == 'writeleash':
                literal = text[1:-1].replace('\\\\', '\\')
                require(not any(prefix in literal for prefix in ('writeleash_price_history_', 'writeleash_price_campaigns_', 'writeleash-price-history', 'writeleash-price-campaigns', 'WriteLeash\\PriceHistory', 'WriteLeash\\PriceCampaigns')), 'flagship references satellite state/runtime')
                require('writeleash_%' not in literal, 'broad family wildcard cleanup')
            if spec['text_domain'] == 'writeleash' and kind in ('T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED'):
                require(not text.lstrip('\\').startswith(('WriteLeash\\PriceHistory', 'WriteLeash\\PriceCampaigns')), 'flagship references satellite namespace')
            if kind == 'T_FUNCTION' and class_depth is None:
                j = i + 1 + (texts[i + 1] == '&')
                if ts[j][0] == 'T_STRING':
                    require(spec['text_domain'] == 'writeleash', 'satellite global function is unreviewed')
                    identifier = ('function', (spec['namespace'] + '\\' + texts[j]).lower())
                    require(identifier not in declarations, 'duplicate function declaration')
                    declarations[identifier] = path
            if kind == 'T_CONST' and class_depth is None:
                require(spec['text_domain'] == 'writeleash', 'satellite global constant is unreviewed')
                identifier = ('constant', spec['namespace'] + '\\' + texts[i + 1])
                require(identifier not in declarations, 'duplicate constant declaration')
                declarations[identifier] = path
            if kind == 'T_STRING' and text.lower() == 'define' and texts[i + 1:i + 2] == ['(']:
                require(spec['text_domain'] == 'writeleash', 'satellite global define is unreviewed')
                require(ts[i + 2][0] == 'T_CONSTANT_ENCAPSED_STRING', 'dynamic constant name')
                identifier = ('constant', texts[i + 2][1:-1])
                require(identifier not in declarations, 'duplicate constant definition')
                declarations[identifier] = path
            if kind in ('T_REQUIRE', 'T_REQUIRE_ONCE', 'T_INCLUDE', 'T_INCLUDE_ONCE'):
                end = texts.index(';', i)
                expression = ''.join(texts[i + 1:end])
                if spec['text_domain'] == 'writeleash' and expression == "ABSPATH.'wp-admin/includes/upgrade.php'":
                    continue
                match = re.fullmatch(r"__DIR__\.(['\"])(/[a-z/-]+\.php)\1", expression)
                require(match, 'dynamic/sibling/unreviewed PHP include')
                included = (Path(path).parent / match[2].lstrip('/')).as_posix()
                require(included in payload, 'include outside target manifest')
                includes.setdefault(path, set()).add(included)
            if spec['text_domain'] != 'writeleash':
                require(text != '`' and kind not in ('T_EVAL', 'T_USE', 'T_NEW', 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR'), 'unreviewed satellite runtime construct')
                require(not (kind == 'T_VARIABLE' and i + 1 < len(ts) and (texts[i + 1] == '(' or ts[i + 1][0] == 'T_DOUBLE_COLON')), 'dynamic satellite callback')
                # Methods are independent; WP/Woo core is the sole external runtime.
                if kind == 'T_CONSTANT_ENCAPSED_STRING':
                    literal = text[1:-1].replace('\\\\', '\\')
                    if literal.startswith(('writeleash_', 'writeleash-')):
                        require(literal.startswith((spec['option_prefix'], spec['scheduler_group'])), 'foreign runtime identifier')
                if 'WriteLeash\\' in text.replace('\\\\', '\\') and kind != 'T_NAMESPACE':
                    require(text.lstrip('\\').startswith(spec['namespace'] + '\\') or text == spec['namespace'], 'foreign namespace reference')
        expected_namespaces = [] if path == 'writeleash.php' or (spec['text_domain'] == 'writeleash' and path == 'uninstall.php') else [spec['namespace']]
        require(namespaces == expected_namespaces, 'wrong/ambiguous PHP namespace: ' + path)
    if spec['text_domain'] != 'writeleash':
        reachable = set()
        pending = [spec['main'], 'uninstall.php']
        while pending:
            path = pending.pop()
            if path in reachable:
                continue
            reachable.add(path)
            pending.extend(includes.get(path, ()))
        require(reachable == {p for p in payload if p.endswith('.php')}, 'unreachable/unowned satellite PHP')
        plugin = payload['includes/class-plugin.php'].decode()
        for const, value in {'VERSION': spec['version'], 'TABLE_PREFIX': spec['table_prefix'],
                             'OPTION_PREFIX': spec['option_prefix'], 'VERSION_OPTION': spec['option_prefix'] + 'version',
                             'SCHEDULER_GROUP': spec['scheduler_group'], 'ACTION_HOOK': spec['action_hook'],
                             'REST_NAMESPACE': spec['rest_namespace']}.items():
            require(len(re.findall(r'public\s+const\s+' + const + r"\s*=\s*'" + re.escape(value) + r"'\s*;", plugin)) == 1,
                    'runtime ownership constant drift: ' + const)
        # #144 scaffold has no SQL, scheduler, REST dispatch, nonce or menu behavior.
        allowed_calls = {'defined', 'register_activation_hook', 'register_deactivation_hook',
                         'class_exists', 'wp_die', 'get_option', 'update_option', 'delete_option', 'array'}
        for path, data in payload.items():
            if not path.endswith('.php'):
                continue
            ts = tokens(data)
            for i, (kind, text) in enumerate(ts[:-1]):
                if kind == 'T_STRING' and ts[i + 1][1] == '(':
                    previous = ts[i - 1][0] if i else ''
                    if previous not in ('T_FUNCTION', 'T_DOUBLE_COLON', 'T_OBJECT_OPERATOR'):
                        require(text in allowed_calls, 'unreviewed scaffold runtime call')
                        if text in ('get_option', 'update_option', 'delete_option'):
                            end = i + 2
                            while end < len(ts) and ts[end][1] not in (',', ')'):
                                end += 1
                            argument = ''.join(part[1] for part in ts[i + 2:end])
                            expected = ("'" + spec['option_prefix'] + "version'" if text == 'delete_option' else 'self::VERSION_OPTION')
                            require(argument == expected, 'dynamic/foreign scaffold option authority')
        uninstall = ''.join(text for _, text in tokens(payload['uninstall.php']))
        require(uninstall.count('delete_option(') == 1 and
                "delete_option('" + spec['option_prefix'] + "version');" in uninstall,
                'uninstall must delete only its exact version option')
    return declarations


def payload_check(payload, spec, paths):
    require(set(payload) == set(paths), 'candidate extra/missing/wrong-plugin file')
    require({spec['main'], 'uninstall.php', 'readme.txt', 'LICENSE'} <= set(paths), 'required target file missing')
    require(all(not re.search(r'(?:^|/)(?:assets|tests|release|wordpress)(?:/|$)|\.(?:md|png|jpg|svg)$', p, re.I)
                for p in paths), 'internal/directory asset in runtime manifest')
    main = payload[spec['main']].decode()
    readme = payload['readme.txt'].decode()
    fields = {'Plugin Name': spec['name'], 'Version': spec['version'], 'Text Domain': spec['text_domain'],
              'Requires Plugins': 'woocommerce', 'Requires at least': spec['requires_wp'],
              'Requires PHP': spec['requires_php'], 'License': 'GPL v2 or later'}
    for key, value in fields.items():
        matches = re.findall(r'^\s*\*\s*' + re.escape(key) + r':\s*([^\r\n]*?)\s*$', main, re.M)
        require(matches == [value], 'target plugin header mismatch: ' + key)
    for key, value in {'Stable tag': spec['version'], 'Requires at least': spec['requires_wp'],
                       'Requires PHP': spec['requires_php'], **({'Requires Plugins': 'woocommerce'} if spec['text_domain'] != 'writeleash' else {})}.items():
        require(re.findall(r'^' + re.escape(key) + r':\s*([^\r\n]*?)\s*$', readme, re.M) == [value], 'target readme mismatch: ' + key)
    require(readme.startswith('=== ' + spec['name'] + ' ===\n'), 'wrong target readme identity')
    require(sum(bool(re.search(rb'^\s*\*?\s*Plugin Name:', data, re.M)) for p, data in payload.items() if p.endswith('.php')) == 1, 'duplicate plugin header')
    import hashlib
    require(hashlib.sha256(payload['LICENSE']).hexdigest() == 'edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6', 'target license drift')
    return php_ownership(payload, spec)


def source_check(root=ROOT):
    registry = targets(root)
    owners = production_inventory(root)
    symbols = {}
    for name, spec in registry.items():
        paths = manifest((root / spec['manifest']).read_bytes())
        require(all(spec['root'] + '/' + p in owners for p in paths if p.endswith('.php')), 'manifest PHP has no owner')
        if name != 'writeleash':
            require(all(set(owners[spec['root'] + '/' + p]) == {name} for p in paths if p.endswith('.php')), 'satellite PHP has ambiguous CI owner')
        payload = {p: (root / spec['root'] / p).read_bytes() for p in paths}
        if name != 'writeleash':
            actual = {p.relative_to(root / spec['root']).as_posix() for p in (root / spec['root']).rglob('*') if p.is_file()}
            require(actual == set(paths), 'unexpected satellite source file (not silently excluded)')
        for file in (root / spec['root']).rglob('*'):
            require(not file.is_symlink(), 'linked production source')
        declarations = payload_check(payload, spec, paths)
        for symbol in declarations:
            require(symbol not in symbols, 'cross-plugin declaration collision')
            symbols[symbol] = name
    return registry
