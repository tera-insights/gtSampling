<?
// This GLA is used to perform a Bernoulli sampling in which the probability
// parameter is continuously adjusted such that the sample size lies in an
// interval specified by the template arguments. The result is the same as if a
// Bernoulli sample had simple been performed with the final value of the
// probability parameter.

// This is done by storing the sample in the state. Whenever it exceeds the
// maximum allowed size, the probability is decreased and the current sample is
// refiltered based on the new probability. This adjustment is repeated until
// the sample size does not exceed the maximum size. If the size of the new
// sample is not at least the minimum size, then the probability is increased
// slightly and the sample is refiltered. This too is repeated until the sample
// size is at least the minimum size. The final size can be below the minimum
// size if not enough items are processed.

// Template Args:
// minimum: The minimum size allowed for the sample.
// maximum: The maximum size allowed for the sample.
// increase: The factor by which the probability is increased by.
// decrease: The factor by which the probability is decreased by.

// Resources:
// algorithm: min
// HashFct.h: CongruentHash

function Adjustable_Bernoulli($t_args, $inputs, $outputs) {
    // Class name is randomly generated.
    $className = generate_name('AdjustableBernoulli');

    // Processing of inputs.
    foreach (array_keys(array_slice($inputs, 0, -1)) as $index => $key)
        $keys["key_$index"] = $inputs[$key];
    $value = end($inputs);
    $inputs_ = $keys;
    $inputs_['value'] = $value;

    // Initialization of local variables from template arguments.
    $minimum  = $t_args['minimum'];
    $maximum  = $t_args['maximum'];
    $increase = pow($t_args['increase'], 1 / count($keys));
    $decrease = pow($t_args['decrease'], 1 / count($keys));

    // The outputs have the same time as inputs.
    $outputs  = array_combine(array_keys($outputs), $inputs);
    $outputs_ = array_combine(array_keys($inputs_), $outputs);

    $sys_headers  = ['algorithm'];
    $user_headers = [];
    $lib_headers  = ['HashFct.h'];
    $libraries    = [];
    $extra        = [];
    $result_type  = ['fragment', 'multi'];
?>

using namespace std;

class <?=$className?>;

class <?=$className?> {
 public:
  struct Iterator {
    // The current index of the result  with regard to the entire sample.
    long index;

    // The stopping point for this iterator.
    long end;

    // The inputs are the fragment index for this iterator, the total number of
    // fragments, and the size of the sample generated by this GLA.
    Iterator(long fragment, long num_fragments, long size)
        : index(fragment * size / num_fragments),
          end(++fragment * size / num_fragments) {
    }
  };

  // The tuples of keys for each input.
  using KeySet = std::tuple<<?=typed($keys)?>>;

  // The type on information being aggregated.
  using Value = <?=$value?>;

  // The type of information being stored in the sample
  using Item = std::pair<KeySet, Value>;

  // The sampling object.
  using Sample = std::vector<Item>;

  // The type of the key hashing.
  using HashType = uint64_t;

  // The minimum size allowed for the sample.
  static const constexpr int kMinimumSize = <?=$minimum?>;

  // The maximum size allowed for the sample.
  static const constexpr int kMaximumSize = <?=$maximum?>;

  // The factor by which the probability is increased by.
  static const constexpr double kIncrease = <?=$increase?>;

  // The factor by which the probability is decreased by.
  static const constexpr double kDecrease = <?=$decrease?>;

  // The maximum number of fragments to split the result into.
  static const constexpr int kNumFragments = 192;

  // The maximum value of HashType.
  static const constexpr HashType kMax = numeric_limits<HashType>::max();

 private:
  // The aggregated sample.
  Sample sample;

  // The current probability parameter.
  double probability;

  // The number of rows processed.
  long count;

  // The number of fragments.
  long num_fragments;

  // The index tracking the output.
  long index;

 public:
  <?=$className?>()
      : sample(),
        probability(1),
        count(0) {
    sample.reserve(kMaximumSize);
  }

  // Basic dynamic array allocation.
  void AddItem(<?=const_typed_ref_args($inputs_)?>) {
    count++;
    KeySet keys(<?=args($keys)?>);
    if (DetermineInclusion(keys)) {
      sample.push_back(Item(keys, value));
      if (sample.size() > kMaximumSize)
        Resize();
    }
  }

  void AddState(<?=$className?> &other) {
    count += other.count;
    if (probability < other.probability) {
      Refilter(sample, other.sample);
    } else if (probability == other.probability) {
      sample.insert(other.sample.end(), sample.begin(), sample.end());
    } else {
      probability = other.probability;
      Sample copy(other.sample);
      Refilter(copy, sample);
      sample.swap(copy);
    }
    Resize();
  }

  int GetNumFragments() {
    return num_fragments = min(kNumFragments, (int) sample.size());
  }

  Iterator* Finalize(int fragment) {
    return new Iterator(fragment, num_fragments, sample.size());
  }

  bool GetNextResult(Iterator* it, <?=typed_ref_args($outputs_)?>) {
    if (it->index == it->end)
      return false;
<?  foreach (array_keys($keys) as $index => $key) { ?>
    <?=$key?> = get<<?=$index?>>(sample[it->index].first);
<?  } ?>
    value = sample[it->index].second;
    it->index++;
    return true;
  }

  void Finalize() {
    index = 0;
  }

  bool GetNextResult(<?=typed_ref_args($outputs_)?>) {
    if (index == sample.size())
      return false;
<?  foreach (array_keys($keys) as $index => $key) { ?>
    <?=$key?> = get<<?=$index?>>(sample[index].first);
<?  } ?>
    value = sample[index].second;
    index++;
    return true;
  }

  // Various getter methods.
  double GetProbability() const { return probability; }
  long GetCount() const { return count; }
  long GetSize() const { return sample.size(); }
  const Sample& GetSample() const { return sample; }

 private:
  // This function determines whether an item is included in the sample based on
  // its keys. It is entirely deterministic and depends only on the keys and the
  // probability of inclusion. The offset is continuously altered in attempt to
  // increase the randomness of selection.
  bool DetermineInclusion(KeySet keys) {
    HashType offset = 1;
<?  for ($index = 0; $index < count($keys); $index++) { ?>
    if (CongruentHash(Hash(get<<?=$index?>>(keys)), offset) > probability * kMax)
      return false;
    else
      offset <<= 1;
<?  } ?>
    return true;
  }

  // Resizes the sample by altering the probability and refiltering the sample.
  void Resize() {
    int original = sample.size();
    while (sample.size() > kMaximumSize) {
      Sample copy;
      copy.reserve(sample.size());
      // The sample is shrunk becasue it is too large.
      probability /= kDecrease;
      Refilter(copy, sample);
      // The sample is enlarged to meet the minimum size.
      while (copy.size() < kMinimumSize) {
        copy.clear();
        probability *= kIncrease;
        Refilter(copy, sample);
      }
      sample.swap(copy);
    }
  }

  // Refilters the current sample based on the current probability and adds the
  // result to copy. The result doesn't overwrite copy to increase flexibility.
  void Refilter(Sample& copy, const Sample& sample) {
    for (auto item : sample)
      if (DetermineInclusion(item.first))
        copy.push_back(item);
  }
};

typedef <?=$className?>::Iterator <?=$className?>_Iterator;

<?
    return [
        'kind'           => 'GLA',
        'name'           => $className,
        'system_headers' => $sys_headers,
        'user_headers'   => $user_headers,
        'lib_headers'    => $lib_headers,
        'libraries'      => $libraries,
        'extra'          => $extra,
        'iterable'       => false,
        'input'          => $inputs,
        'output'         => $outputs,
        'result_type'    => $result_type,
    ];
}
?>